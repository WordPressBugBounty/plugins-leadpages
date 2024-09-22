<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;
use Leadpages\models\DB;
use Leadpages\models\Options;

/**
 * This class handles plugin operations that need to happen on plugin activation/deactivation
 * or when the plugin is updated to a new version. It is only used in the plugins Core class.
 *
 * Note: we are not doing anything on plugin activation. If this changes in the future we should
 * create a new method in this class and use the register_activation_hook() function to register it
 * in the Core class.
 *
 */
class Activator {

    use Utils;

    /** @var DB $db */
    private $db;

    public function __construct() {
        $this->db = new DB();
    }

    /**
     * This method gets fired when the user deactivates the plugin.
     *
     * On deactivation, we delete the access and refresh tokens from the options table but leave the
     * rest of the users data intact, should they wish to reactivate later to resume the work they had done.
     */
    public function deactivate() {
        Options::delete(Options::$refresh_token);
        Options::delete(Options::$access_token);
    }

    /**
     * Ensure that the database schema this plugin is dependent on is up to date for the current
     * version of the plugin, whatever that might be.
     *
     * An admin notice will be shown if the database update failed. This would be horrendous if
     * it occurred but there is little we can do and need to bubble it up to the user.
     */
    public function update_db_check() {
        $lpdb = $this->db;
        $current_db_version = Options::get(Options::$db_version);

        try {
            // if the database version is not set we need to install all tables
            // otherwise migrate the database to the latest version
            if (! $current_db_version) {
                $this->debug('Installing leadpages database tables');
                $lpdb->install();
            } else {
                $this->debug("Migrating leadpages database tables from $current_db_version to " . LEADPAGES_VERSION);
                $lpdb->migrate($current_db_version);
            }
        } catch (\Exception $e) {
            add_action(
                'admin_notices',
                function () {
                    echo wp_kses(
                        "<div class='notice notice-error is-dismissible'><p>
                            Leadpages encountered an error while installing/updating its database tables. 
                            You can try refreshing the page or
                            <a href='https://support.leadpages.com/hc/en-us/articles/205046170'>
                                contact support
                            </a>
                            for assistance.
                        </p></div>",
                        [
                            'div' => [
                                'class' => [],
                            ],
                            'p'   => [],
                            'a'   => [
                                'href' => [],
                            ],
                        ]
                    );
                }
            );
        }
    }
}
