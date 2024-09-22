<?php

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

if (! function_exists('leadpages_skip_wp_admin_notice')) {
    /**
     * Show an admin notice to administrators when the minimum WP version
     * could not be reached. The error message is only in english available.
     */
    function leadpages_skip_wp_admin_notice() {
        if (current_user_can('install_plugins')) {
            $data = get_plugin_data(LEADPAGES_FILE, true, false);
            global $wp_version;
            echo '<div class=\'notice notice-error\'>
				<p><strong>' .
                esc_html($data['Name']) .
                '</strong> could not be initialized because you need minimum WordPress version ' .
                esc_html(LEADPAGES_MIN_WP) .
                ' ... you are running: ' .
                esc_html($wp_version) .
                '.
				<a href="' .
                esc_url(admin_url('update-core.php')) .
                '">Update WordPress now.</a>
			</div>';
        }
    }
}
add_action('admin_notices', 'leadpages_skip_wp_admin_notice');
