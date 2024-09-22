<?php

/**
 * This file is automatically requested when the user uninstalls the plugin.
 *
 * @see https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

global $wpdb;

$landing_pages_table = $wpdb->prefix . 'lp_landingpages';
$sql = "DROP TABLE IF EXISTS $landing_pages_table";
// phpcs:ignore WordPress.DB.PreparedSQL
$wpdb->query($sql);

delete_option('lp_last_page_sync_date');
delete_option('lp_db_version');
delete_option('lp_access_token');
delete_option('lp_refresh_token');
delete_option('lp_code_verifier');
