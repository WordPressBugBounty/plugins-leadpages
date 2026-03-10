<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request


/**
 * A class for managing a cache for Leadpages asset serving backed by the WP Transients API.
 *
 * WordPress Transients API: https://developer.wordpress.org/apis/transients/
 *   Transient expiration times are a maximum time. There is no minimum age. Transients
 *   might disappear one second after you set them, or 24 hours, but they will never be
 *   around after the expiration time.
 *
 * Pages are cached by the slug that they are published under in WordPress so that they
 * can be quickly referenced when serving. The entire response when fetching the page
 * is cached, not just the page HTML content.
 */
class Cache {
    // The maximum amount of time a value should be cached for
    private static $max_time = 60 * 60 * 24; // 1 day

    // The prefix to cache pages with
    private static $page_key_prefix = LEADPAGES_OPT_PREFIX . '_page_';

    /*
     * Build the cache key given the slug of the page.
     *
     * @param string $slug
     * @return string
     */
    public static function page_key( $slug ) {
        return self::$page_key_prefix . $slug;
    }

    /*
     * Set a value in the cache.
     *
     * Example:
     *   Cache::set(Cache::page_key($slug), $page)
     *
     * @param string $name
     * @param mixed $value
     * @return boolean
     */
    public static function set( $name, $value ) {
        return set_transient($name, $value, self::$max_time);
    }

    /*
     * Retrieve a value from the cache.
     *
     * @param string $name
     * @return mixed
     */
    public static function get( $name ) {
        return get_transient($name);
    }

    /*
     * Remove a value from the cache.
     *
     * @param string $name
     * @return boolean
     */
    public static function delete( $name ) {
        return delete_transient($name);
    }
}
