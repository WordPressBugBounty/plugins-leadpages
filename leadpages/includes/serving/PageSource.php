<?php

namespace Leadpages\serving;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * A strategy describing how to serve a connected landing page from a particular Leadpages backend
 * (Classic or Nova). The Proxy performs the shared reverse-proxy mechanics (fetch with retry,
 * render, cache) and defers every backend-specific decision to a PageSource so one install can
 * serve some slugs from Classic and others from Nova.
 *
 * @see \Leadpages\serving\ClassicPageSource
 * @see \Leadpages\serving\NovaPageSource
 */
interface PageSource {

    /**
     * The upstream URL to fetch for this page and the public WordPress URL being served.
     *
     * @param object $page the landing page row
     * @param string $wp_url the public WordPress URL of the current request (scheme + host + path)
     * @return string
     */
    public function build_url( $page, string $wp_url ): string;

    /**
     * The wp_remote_request options for the upstream fetch (forwarded cookies, X-Forwarded-For,
     * timeout, etc.).
     *
     * @return array
     */
    public function build_request_options(): array;

    /**
     * Whether the upstream body should be served when the upstream returns 404. Classic serves the
     * Leadpages 404 page; Nova declines so it never echoes an error/"under construction" page at the
     * customer's URL.
     *
     * @return bool
     */
    public function serves_not_found(): bool;

    /**
     * The "leadpages-serving-tags" meta value identifying this backend's WordPress traffic.
     *
     * @return string
     */
    public function serving_tag(): string;

    /**
     * The cookies from the upstream response that should be mirrored back to the visitor.
     *
     * @param array $response the upstream wp_remote_request response
     * @return \WP_Http_Cookie[]
     */
    public function cookies_to_mirror( $response ): array;

    /**
     * The cache key under which this page's response is stored.
     *
     * @param object $page the landing page row
     * @return string
     */
    public function cache_key( $page ): string;

    /**
     * How long (in seconds) the upstream response may be cached, or null if it must not be cached.
     *
     * @param object $page the landing page row
     * @param array $response the upstream wp_remote_request response
     * @return int|null null to skip caching
     */
    public function cache_ttl( $page, $response ): ?int;
}
