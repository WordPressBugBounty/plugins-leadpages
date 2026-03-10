<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\http\exceptions\NotFoundException;
use Leadpages\models\Page;
use Leadpages\models\Options;

/**
 * A class for serving Leadpages assets within the WordPress environment.
 */
class Proxy {

    use Utils;

    /** @var Client */
    private $client;

    public function __construct() {
        $this->client = new Client();
    }

    /**
     * Proxy requests to Leadpages landing pages when a page has been connected
     * for the requested path. Ignores any request methods that are not GET and ignores
     * any paths not associated with a Leadpages landing page. If the page being served
     * is a split test, the cookie identifying the split test variation is set.
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

        $cached_value = Cache::get(Cache::page_key($slug));
        if ($cached_value) {
            $this->debug('Serving page from cache');
            $this->render_html($cached_value);
        } else {
            $page = Page::get_by_slug($slug);
            // do not serve unpublished, unconnected or deleted pages, or pages that are variations of a splittest
            if (! $page || ! $page->current_edition || ! $page->connected || $page->deleted_at || $page->split_test) {
                $this->debug("Ignoring request to $current_url");
                return;
            }

            $target_url = esc_url_raw($page->published_url, [ 'http', 'https' ]);
            $this->debug("Proxying $current_url to $target_url");
            $response = $this->fetch_page_html($target_url);
            if (! $response) {
                $this->debug('Something went wrong, aborting proxy');
                return;
            }

            $this->render_html($response);

            // Cache the page only if it is not a split test. Split tests need to
            // be fetched each time so that our system can generate the HTML based
            // on the split test cookie.
            if ('LeadpageSplitTestV2' !== $page->kind) {
                Cache::set(Cache::page_key($slug), $response);
            }
        }

        $end = microtime(true);
        $time_taken = ( $end - $start ) * 1000;
        $this->debug("Successful served page in $time_taken ms");

        $this->lp_exit(0);
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
     * Get the page content for a provided url. Leadpages "variation" cookies will automatically be forwarded
     * with the request.
     *
     * @param string $url
     * @param bool $retry whether or not to retry the request on server errors
     * @return \WP_HTTP_Response|null response object or null if the 500 and 400 errors (other than 404)
     */
    private function fetch_page_html( $url, $retry = true ) {
        $url = esc_url_raw($url);

        $options = [ 'timeout' => 10 ];

        // Transfer potential split test cookies from incoming request to proxied request.
        // Only process the "variation" cookie if it exists.
        if (isset($_COOKIE['variation'])) {
            $variation_cookie = $_COOKIE['variation'];
            $cookie = new \WP_Http_Cookie('variation');
            $cookie->name = 'variation';
            $cookie->value = $variation_cookie;
            $options['cookies'] = [ $cookie ];
        }

        try {
            $response = $this->client->get($url, $options);
        } catch (NotFoundException $e) {
            $response = $e->response;
        } catch (ServerException $e) {
            $status_code = wp_remote_retrieve_response_code($e->response);
            if ($status_code >= 500 && $retry) {
                $response = $this->fetch_page_html($url, false);
            } else {
                $response = null;
            }
        } catch (\Exception $e) {
            $response = null;
        }

        return $response;
    }

    /**
     * Render HTML from an HTTP response object to the page with the same status
     * code and variation cookie (if set) as the provided response.
     *
     * @param \WP_HTTP_Response $response
     */
    public function render_html( $response ) {
        if (ob_get_length() > 0) {
            ob_clean();
        }

        $html = $response['body'];
        $status = wp_remote_retrieve_response_code($response);
        $split_test_cookie = wp_remote_retrieve_cookie($response, 'variation');

        status_header($status);
        if ($split_test_cookie) {
            setcookie(
                $split_test_cookie->name,
                $split_test_cookie->value,
                $split_test_cookie->expires ?? 0
            );
        }

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
        return esc_url_raw(( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
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
     * helps us differentiate WordPress traffic in our system.
     *
     * @param string $content html
     * @return string
     */
    public static function modify_serving_tags( $content ) {
        $search = '</head>';
        $replace = '<meta name="leadpages-serving-tags" content="wordpress-official"></head>';
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
