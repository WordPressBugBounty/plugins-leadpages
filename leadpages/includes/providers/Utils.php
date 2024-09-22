<?php

namespace Leadpages\providers;

use Leadpages\models\Options;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * Helpful utility methods that can be used in all classes
 */
trait Utils {

    /**
     * Simple-to-use error_log debug log when debugging is enabled
     *
     * @param  mixed  $message          The message
     * @param  string $method_or_function __METHOD__ or __FUNCTION__
     * @return string
     */
    public function debug( $message, $method_or_function = null ) {
        if (WP_DEBUG) {
            $log =
                ( empty($method_or_function) ? '' : '(' . $method_or_function . ')' ) .
                ': ' .
                ( is_string($message) ? $message : wp_json_encode($message) );
            $log = 'LEADPAGES_DEBUG' . $log;
            // phpcs:disable
            error_log($log);
            // phpcs:enable
            return $log;
        }
        return '';
    }

    /**
     * A wrapper around the exit construct for testing purposes
     */
    protected function lp_exit( $code = 0 ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit($code);
    }

    /**
     * Is the user currently logged in to the plugin through the Leadpages OAuth2 flow.
     * Logged status is determined by existence of both the refresh and access tokens in the
     * options table, regardless of whether they are expired or not.
     * @return bool
     */
    public function is_user_logged_into_plugin() {
        return !empty(Options::get(Options::$refresh_token)) && !empty(Options::get(Options::$access_token));
    }
}
