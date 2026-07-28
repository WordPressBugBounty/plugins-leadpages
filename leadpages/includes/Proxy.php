<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\http\exceptions\NotFoundException;
use Leadpages\models\Page;
use Leadpages\models\Options;
use Leadpages\serving\PageSource;
use Leadpages\serving\ClassicPageSource;
use Leadpages\serving\NovaPageSource;

/**
 * A class for serving Leadpages assets within the WordPress environment.
 *
 * The reverse-proxy mechanics (fetch, render, cache) are shared; the backend-specific behavior for
 * a connected page comes from a PageSource selected by the row's platform (Classic or Nova).
 */
class Proxy {

    use Utils;

    /** @var Client */
    private $client;

    /** The serving-tag meta value injected into the current response (set per request in render). */
    private static $serving_tag = 'wordpress-official';

    public function __construct() {
        $this->client = new Client();
    }

    /**
     * Proxy requests to connected Leadpages landing pages. Ignores any request methods that are not
     * GET and any paths not associated with a connected landing page. The page is served through the
     * PageSource for its platform.
     *
     * "path" and "slug" are used synonymously here and are the same as a WP "permalink"
     *
     * Renders the page html and exits the current process so no other code can execute.
     *
     * @return void
     */
    public function serve_landing_page() {
        $request_method = sanitize_key($_SERVER['REQUEST_METHOD']);
        if ('get' !== $request_method) {
            return;
        }

        $start = microtime(true);

        $current_url = $this->get_current_url();
        $slug = sanitize_title($this->parse_request($current_url));

        $page = Page::get_by_slug($slug);
        if (! $page) {
            $this->debug("Ignoring request to $current_url");
            return;
        }

        $source = $this->source_for($page);
        $cache_key = $source->cache_key($page);

        $cached_value = Cache::get($cache_key);
        if ($cached_value) {
            $this->debug('Serving page from cache');
            $this->render_html($cached_value, $source);
        } else {
            // Do not serve unpublished, deleted, or split-test-variation pages.
            if (! $page->current_edition || ! $page->connected || $page->deleted_at || $page->split_test) {
                $this->debug("Ignoring request to $current_url");
                return;
            }

            $wp_url = strtok($current_url, '?');
            $this->debug("Proxying $current_url");
            $response = $this->fetch_page_html($source, $page, $wp_url);
            if (! $response) {
                $this->debug('Something went wrong, aborting proxy');
                return;
            }

            $this->render_html($response, $source);

            // Never cache a response that carries Set-Cookie: the cached copy would replay that
            // same cookie value to every visitor served from cache (a shared-identity vector).
            // Per-visitor experiment/personalization responses are already no-store (ttl null);
            // this also covers a cacheable page that happens to set a first-party cookie.
            $ttl = $source->cache_ttl($page, $response);
            if (null !== $ttl && empty(wp_remote_retrieve_cookies($response))) {
                Cache::set($cache_key, $response, $ttl);
            }
        }

        $end = microtime(true);
        $time_taken = ( $end - $start ) * 1000;
        $this->debug("Successful served page in $time_taken ms");

        $this->lp_exit(0);
    }

    /**
     * Select the serving strategy for a page based on its platform.
     *
     * @param object $page
     * @return PageSource
     */
    private function source_for( $page ) {
        if (isset($page->platform) && 'nova' === $page->platform) {
            return new NovaPageSource();
        }
        return new ClassicPageSource();
    }

    /**
     * Strip the base url and params out of the request url and differentiate the
     * result against the users specified permalink structure. The result will be
     * the slug we can expect a landing page to be published under.
     *
     * @param string $url
     * @return string
     */
    private function parse_request( $url ) {
        $path_and_params = substr($url, strlen(home_url()));
        $path = explode('?', $path_and_params);
        $tokens = explode('/', $path[0]);

        $permalink_structure = $this->clean_permalink_for_leadpage();
        $tokens = array_diff($tokens, $permalink_structure);

        foreach ($tokens as $index => $token) {
            if (empty($token)) {
                unset($tokens[ $index ]);
            } else {
                $tokens[ $index ] = sanitize_title($token);
            }
        }
        $tokens = array_values($tokens);
        $slug = implode('/', $tokens);

        return $slug;
    }


    /**
     * Get the WordPress permalink structure with any %parameters% removed
     *
     * @return string[]
     */
    private function clean_permalink_for_leadpage() {
        $permalink_structure = explode('/', Options::get(Options::$permalink_structure));
        foreach ($permalink_structure as $key => $value) {
            if (empty($value) || strpos($value, '%') !== false) {
                unset($permalink_structure[ $key ]);
            }
        }
        return $permalink_structure;
    }

    /**
     * Fetch the upstream page HTML through the given source. Retries once on server errors. Returns
     * the response to render, or null to decline serving (letting WordPress handle the request).
     *
     * @param PageSource $source
     * @param object $page
     * @param string $wp_url the public WordPress URL being served
     * @param bool $retry whether to retry the request on server errors
     * @return array|null response object or null
     */
    private function fetch_page_html( $source, $page, $wp_url, $retry = true ) {
        $url = $source->build_url($page, $wp_url);
        $options = $source->build_request_options();

        try {
            $response = $this->client->get($url, $options);
        } catch (NotFoundException $e) {
            // Classic serves the Leadpages 404 page; Nova declines so it never echoes an error page.
            $response = $source->serves_not_found() ? $e->response : null;
        } catch (ServerException $e) {
            $status_code = wp_remote_retrieve_response_code($e->response);
            if ($status_code >= 500 && $retry) {
                $response = $this->fetch_page_html($source, $page, $wp_url, false);
            } else {
                $response = null;
            }
        } catch (\Exception $e) {
            $response = null;
        }

        return $response;
    }

    /**
     * Render HTML from an HTTP response object to the page with the same status code as the response
     * and mirror the source's cookies back to the visitor.
     *
     * @param array $response
     * @param PageSource $source
     */
    public function render_html( $response, $source ) {
        if (ob_get_length() > 0) {
            ob_clean();
        }

        $html = $response['body'];
        $status = wp_remote_retrieve_response_code($response);

        status_header($status);
        foreach ($source->cookies_to_mirror($response) as $cookie) {
            // Mirror on the WordPress host with a site-wide path so per-visitor experiment /
            // personalization cookies are sent on every page (not just the proxied slug's
            // directory, which is what a bare setcookie() would default to). Mark Secure on
            // HTTPS and SameSite=Lax. HttpOnly is intentionally NOT forced: Nova's client-side
            // scripts read experiment cookies (e.g. _hp_exp_*) from document.cookie.
            setcookie(
                $cookie->name,
                $cookie->value,
                [
                    'expires'  => $cookie->expires ?? 0,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'samesite' => 'Lax',
                ]
            );
        }

        self::$serving_tag = $source->serving_tag();

        ob_start([ get_called_class(), 'preprocess_html' ]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        ob_end_flush();
    }

    /**
     * Wrap the exit construct for testing purposes
     */
    public function lp_exit() {
        exit(0);
    }

    private static function get_current_url() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return esc_url_raw(( is_ssl() ? 'https://' : 'http://' ) . $host . $uri);
    }

    /**
     * Process HTML content for output buffering.
     *
     * @param string $content html
     * @return string
     */
    public static function preprocess_html( $content ) {
        $html = self::modify_url_tag($content);
        $html = self::modify_serving_tags($html);
        return $html;
    }

    /**
     * Output buffering callback to add a "leadpages-serving-tags" meta tag for analytics. This
     * helps us differentiate WordPress traffic (and Classic vs Nova) in our system.
     *
     * @param string $content html
     * @return string
     */
    public static function modify_serving_tags( $content ) {
        $search = '</head>';
        $replace = '<meta name="leadpages-serving-tags" content="' . self::$serving_tag . '"></head>';
        return str_replace($search, $replace, $content);
    }

    /**
     * Output buffering callback for "og:url" meta tag. Replace the tag content with
     * the url of the WordPress page.
     *
     * Open Graph meta tags are snippets of code that control how URLs are displayed when shared
     * on social media. A link to this page shared on social media should point to the users WP
     * site and not the page within Leadpages.
     *
     * @param string $content html
     * @return string
     */
    public static function modify_url_tag( $content ) {
        global $wp;
        if (empty($wp)) {
            // we can't build the correct WP url in this case, so return the original page
            return $content;
        }

        $url = self::get_current_url();
        $regex = '/(<meta property="og:url" content=")[^"]+(">)/';
        $html = preg_replace($regex, '${1}' . $url . '${2}', $content);
        if (null === $html) {
            // An error occured so we return the original content
            return $content;
        }
        return $html;
    }
}
