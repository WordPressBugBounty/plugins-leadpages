<?php

/**
 * This file is automatically requested when the user uninstalls the plugin.
 *
 * @see https://developer.wordpress.org/plugins/the-basics/uninstall-methods/
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// The pages table and options are shared by every copy of this plugin on the site. Leave them when
// this file was reached through another plugin in this folder (the old Leadpages Connector, if a copy
// of this plugin was unpacked into its folder), or while another copy of this plugin is still on disk.
// WordPress defines WP_UNINSTALL_PLUGIN once per request, so in a bulk delete it can name a plugin
// deleted earlier in the same request; compare folders rather than trust it for this copy.
$lp_folder = basename(__DIR__);
if (dirname(WP_UNINSTALL_PLUGIN) === $lp_folder && 'leadpages-official.php' !== basename(WP_UNINSTALL_PLUGIN)) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach (array_keys(get_plugins()) as $plugin_file) {
    if (
        'leadpages-official.php' === basename($plugin_file)
        && dirname($plugin_file) !== $lp_folder
        && file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)
    ) {
        return;
    }
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
delete_option('lp_platform');
delete_option('lp_nova_org_id');
delete_option('lp_nova_code_verifier');
delete_option('lp_nova_oauth_state');
delete_option('lp_nova_access_token');
delete_option('lp_nova_refresh_token');
delete_option('lp_nova_popup_id');
delete_option('lp_nova_popup_scope');
delete_option('lp_legacy_import');
delete_option('lp_legacy_import_lock');
