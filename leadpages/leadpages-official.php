<?php

/**
 * Plugin Name:       Leadpages
 * Plugin URI:        https://leadpages.com/integrations/wordpress
 * Description:       Easily publish your Leadpages landing pages to your WordPress site. Promote your lead magnets, events, promotions, and more.
 * Version:           1.1.3
 * Author:            Leadpages
 * Author URI:        https://leadpages.com
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 */

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * Plugin constants.
 */
if (defined('LEADPAGES_PATH')) {
    return;
}
define('LEADPAGES_FILE', __FILE__);
define('LEADPAGES_PATH', dirname(LEADPAGES_FILE));
define('LEADPAGES_SLUG', basename(LEADPAGES_PATH));
define('LEADPAGES_MIN_PHP', '8.0.0');
define('LEADPAGES_MIN_WP', '6.0.0');
define('LEADPAGES_NS', 'leadpages');
define('LEADPAGES_DB_PREFIX', 'lp'); // The table name prefix wp_{prefix}
define('LEADPAGES_OPT_PREFIX', 'lp'); // The option name prefix in wp_options
define('LEADPAGES_VERSION', '1.1.3');

require_once LEADPAGES_PATH . '/vendor/autoload.php';

use Leadpages\Core;
global $wp_version;

// Check PHP and WP Versions and print notice if minimum not reached, otherwise start the plugin
if (version_compare(phpversion(), LEADPAGES_MIN_PHP, '<')) {
    require_once LEADPAGES_PATH . '/includes/other/fallback-php-version.php';
} elseif (version_compare($wp_version, LEADPAGES_MIN_WP, '<')) {
    require_once LEADPAGES_PATH . '/includes/other/fallback-wp-version.php';
} else {
    $core = Core::get_instance();
    $core->run();
}
