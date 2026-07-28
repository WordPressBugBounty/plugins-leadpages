<?php

namespace Leadpages\serving;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\Cache;
use Leadpages\providers\config\Config;

/**
 * Serves a Nova (new Leadpages) page through the public raw-HTML endpoint:
 *
 *   GET {NOVA_APP_URL}/api/pages/{lp_slug}/raw?pid={nova_page_id}&serving_tag=wordpress&wp_url={wp_url}
 *
 * The pid is mandatory because Nova slugs are owner-scoped and can collide. To make per-visitor
 * experiments and personalization work through the proxy, all inbound cookies are forwarded
 * upstream, all upstream Set-Cookie headers are mirrored back, and the visitor IP is forwarded via
 * X-Forwarded-For. Caching is driven by the upstream Cache-Control so drafts / running experiments /
 * personalized responses (no-store / private) are never cached.
 */
class NovaPageSource implements PageSource {

    /** The maximum time (seconds) a Nova response may be cached, so editor changes go live quickly. */
    private const MAX_TTL = 60;

    /** @var Config */
    private $config;

    public function __construct() {
        $this->config = Config::get_instance();
    }

    /**
     * @param object $page
     * @param string $wp_url the public WordPress URL being served
     * @return string
     */
    public function build_url( $page, string $wp_url ): string {
        $base = untrailingslashit($this->config->get('NOVA_APP_URL'))
            . '/api/pages/' . rawurlencode($page->lp_slug) . '/raw';

        $query = http_build_query([
            'pid'         => $page->nova_page_id,
            'serving_tag' => 'wordpress',
            'wp_url'      => $wp_url,
        ]);

        return $base . '?' . $query;
    }

    /**
     * Forward all inbound cookies and the visitor IP upstream so Nova's server-side experiments and
     * personalization key off the real visitor rather than the WordPress server.
     *
     * @return array
     */
    public function build_request_options(): array {
        $options = [ 'timeout' => 10 ];

        $cookies = [];
        foreach (wp_unslash($_COOKIE) as $name => $value) {
            if (! is_string($value)) {
                // Skip array-shaped cookies; only simple name/value pairs are forwarded.
                continue;
            }
            if (! self::should_forward_cookie($name)) {
                // Never leak WordPress's own auth/session/preference cookies to the external
                // Nova origin. Only visitor cookies Nova may need (experiments/personalization)
                // are forwarded.
                continue;
            }
            $cookie = new \WP_Http_Cookie($name);
            $cookie->name = $name;
            $cookie->value = sanitize_text_field($value);
            $cookies[] = $cookie;
        }
        if ($cookies) {
            $options['cookies'] = $cookies;
        }

        // Prefer Cloudflare's real-visitor header when present: behind a CDN, REMOTE_ADDR is the edge
        // IP, so Nova would see one IP for every visitor and its geo/personalization would be wrong.
        // CF-Connecting-IP is set by Cloudflare itself; a raw client-supplied X-Forwarded-For is not
        // trusted (spoofable).
        $forwarded_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP'])
            ? $_SERVER['HTTP_CF_CONNECTING_IP']
            : ( isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '' );
        $visitor_ip = filter_var(wp_unslash($forwarded_ip), FILTER_VALIDATE_IP);
        if ($visitor_ip) {
            $options['headers'] = [ 'X-Forwarded-For' => $visitor_ip ];
        }

        return $options;
    }

    /**
     * Whether an inbound cookie may be forwarded upstream to Nova. WordPress's own auth, session,
     * and preference cookies must never be sent to an external origin — forwarding them served no
     * purpose and leaked WP session material cross-origin. Cookies Nova itself sets for
     * experiments/personalization (e.g. `_hp_*`, `variation`) are not blocked.
     *
     * @param string $name
     * @return bool
     */
    private static function should_forward_cookie( string $name ): bool {
        $blocked_prefixes = [ 'wordpress_', 'wp-settings', 'wp_', 'wp-postpass', 'comment_author' ];
        $lower = strtolower($name);
        foreach ($blocked_prefixes as $prefix) {
            if (strncmp($lower, $prefix, strlen($prefix)) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Decline to serve a Nova 404 so the plugin never echoes a Nova error/"under construction" page
     * at the customer's URL; WordPress renders its own 404 instead.
     *
     * @return bool
     */
    public function serves_not_found(): bool {
        return false;
    }

    /**
     * @return string
     */
    public function serving_tag(): string {
        return 'wordpress-official-nova';
    }

    /**
     * Mirror all upstream Set-Cookie headers back to the visitor (per-visitor experiments).
     *
     * @param array $response
     * @return \WP_Http_Cookie[]
     */
    public function cookies_to_mirror( $response ): array {
        $cookies = wp_remote_retrieve_cookies($response);
        return is_array($cookies) ? $cookies : [];
    }

    /**
     * @param object $page
     * @return string
     */
    public function cache_key( $page ): string {
        return Cache::page_key($page->wp_slug, 'nova', $page->nova_page_id);
    }

    /**
     * Cache only when the upstream explicitly allows it, capped so edits go live quickly. Drafts,
     * running experiments and personalized responses are marked no-store / private and never cached.
     *
     * @param object $page
     * @param array $response
     * @return int|null
     */
    public function cache_ttl( $page, $response ): ?int {
        $cache_control = strtolower(wp_remote_retrieve_header($response, 'cache-control'));
        if ('' === $cache_control) {
            return null;
        }
        if (
            false !== strpos($cache_control, 'no-store')
            || false !== strpos($cache_control, 'no-cache')
            || false !== strpos($cache_control, 'private')
        ) {
            return null;
        }
        if (preg_match('/max-age=(\d+)/', $cache_control, $matches)) {
            return min((int) $matches[1], self::MAX_TTL);
        }
        return null;
    }
}
