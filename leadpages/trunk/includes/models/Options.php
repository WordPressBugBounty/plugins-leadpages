<?php

namespace Leadpages\models;

defined('ABSPATH') || die('No script kidd');

/*
 * A class to centralize all of our use of the options API with wrapper methods to work with them.
 */
class Options {

    // The last time we synced the pages from leadpages.com
    public static $last_page_sync_date = LEADPAGES_OPT_PREFIX . '_last_page_sync_date';

    // The current version of the database (corresponds to the version of the plugin an update was last required)
    public static $db_version = LEADPAGES_OPT_PREFIX . '_db_version';

    // The permalink structure set in WordPress
    // We do not create this option but we do use it. It should be readonly.
    public static $permalink_structure = 'permalink_structure';

    // The code_verifier used in the OAuth 2.0 authorization code flow
    public static $code_verifier = LEADPAGES_OPT_PREFIX . '_code_verifier';

    // The refresh and access tokens for leadpages.com OAuth 2.0. The existence of these
    // tokens is how we determine the users authenticated status.
    public static $refresh_token = LEADPAGES_OPT_PREFIX . '_refresh_token';
    public static $access_token = LEADPAGES_OPT_PREFIX . '_access_token';

    /*
     * Get and return an option with the given name
     *
     * @param string $name
     * @return mixed
     */
    public static function get( $name ) {
        return get_option($name);
    }

    /*
     * Set (create or update) an option given the name and it's new value
     *
     * @param string $name
     * @param mixed $value
     * @return boolean
     */
    public static function set( $name, $value ) {
        return update_option($name, $value);
    }

    /*
     * Remove an option with the given name
     *
     * @param string $name
     * @return boolean
     */
    public static function delete( $name ) {
        return delete_option($name);
    }

    /*
     * Remove all of the options this plugin uses.
     *
     * Used in uninstall.php to clear all plugin option data
     */
    public static function delete_all() {
        self::delete(self::$last_page_sync_date);
        self::delete(self::$db_version);
        self::delete(self::$refresh_token);
        self::delete(self::$access_token);
    }
}
