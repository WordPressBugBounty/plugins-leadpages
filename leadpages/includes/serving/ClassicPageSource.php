<?php

namespace Leadpages\serving;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\Cache;

/**
 * Serves a Classic Leadpages page. This is the reverse-proxy behavior the plugin has always had,
 * extracted verbatim behind the PageSource strategy: fetch the page's published_url, forward only
 * the split-test "variation" cookie, mirror the "variation" Set-Cookie back, serve Leadpages 404
 * pages, and never cache split tests.
 */
class ClassicPageSource implements PageSource {

    /**
     * @param object $page
     * @param string $wp_url
     * @return string
     */
    public function build_url( $page, string $wp_url ): string {
        return esc_url_raw($page->published_url, [ 'http', 'https' ]);
    }

    /**
     * @return array
     */
    public function build_request_options(): array {
        $options = [ 'timeout' => 10 ];

        // Transfer a potential split test cookie from the incoming request to the proxied request.
        // Only process the "variation" cookie if it exists. Preserved verbatim from the original
        // Proxy so Classic serving behavior is unchanged.
        if (isset($_COOKIE['variation'])) {
            $variation_cookie = sanitize_text_field(wp_unslash($_COOKIE['variation']));
            $cookie = new \WP_Http_Cookie('variation');
            $cookie->name = 'variation';
            $cookie->value = $variation_cookie;
            $options['cookies'] = [ $cookie ];
        }

        return $options;
    }

    /**
     * Classic serves the Leadpages 404 page (existing behavior).
     *
     * @return bool
     */
    public function serves_not_found(): bool {
        return true;
    }

    /**
     * @return string
     */
    public function serving_tag(): string {
        return 'wordpress-official';
    }

    /**
     * Classic mirrors only the split-test "variation" cookie.
     *
     * @param array $response
     * @return \WP_Http_Cookie[]
     */
    public function cookies_to_mirror( $response ): array {
        $variation_cookie = wp_remote_retrieve_cookie($response, 'variation');
        return $variation_cookie ? [ $variation_cookie ] : [];
    }

    /**
     * @param object $page
     * @return string
     */
    public function cache_key( $page ): string {
        return Cache::page_key($page->wp_slug);
    }

    /**
     * Cache everything except split tests, which must be fetched each time so Leadpages can pick
     * the variation from the split-test cookie.
     *
     * @param object $page
     * @param array $response
     * @return int|null
     */
    public function cache_ttl( $page, $response ): ?int {
        if ('LeadpageSplitTestV2' === $page->kind) {
            return null;
        }
        return Cache::default_ttl();
    }
}
