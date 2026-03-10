<?php

namespace Leadpages\models;

defined('ABSPATH') || die('No script kiddies please!');

use Leadpages\providers\Utils;
use Leadpages\models\Page;
use Leadpages\models\Options;

/**
 * A class responsible for managing the database schema of the plugin.
 */
class DB {

    use Utils;

    /**
     * A list of all of the database versions that the plugin may need to migrate over
     * to reach the current version. Database versions are equivalent to plugin versions
     * but not all plugin versions will have a corresponding database version in this list.
     * Only when a change to the database schema is needed will the version be recorded here.
     */
    public static $db_versions = [
        '1.0.0',
        // versions must be ordered from oldest to newest - top to bottom
    ];

    /**
     * On a fresh activation of our plugin.
     *
     * Create all database tables by migrating over all
     * of the database versions to the current version.
     */
    public function install() {
        foreach (self::$db_versions as $version) {
            $this->call_migration($version);
            Options::set(Options::$db_version, $version);
        }
    }

    /**
     * On a plugin update.
     *
     * Migrate the database to the latest version by migrating over all of the database
     * versions from the previous version to the current version. If the previous version
     * is the last version no operation will occur.
     *
     * @param string $current_db_version
     * @returns void
     * @throws \Exception
     */
    public function migrate( $current_db_version ) {
        // Get the index of the current version in our list of all versions
        $current_index = array_search($current_db_version, self::$db_versions, true);
        $last_index = count(self::$db_versions) - 1;

        if ($current_index === $last_index) {
            $this->debug('Already at the latest version. Nothing to migrate.');
            return;
        }

        // If the previous version was found in the array, start the migration from there
        if (false !== $current_index) {
            $start = $current_index + 1;
            $end = count(self::$db_versions);

            for ($i = $start; $i < $end; $i++) {
                $version = self::$db_versions[ $i ];
                $this->call_migration($version);
                Options::set(Options::$db_version, $version);
            }
        }
    }

    /**
     * Call the class method corresponding to the version of the database
     *
     * @param string $version example: 1.2.3
     * @returns void
     * @throws \Exception
     */
    private function call_migration( $version ) {
        $this->debug('DB migration for version ' . $version);
        // Convert the version to a format that matches the method names
        // Example: 1.2.3 -> v1_2_3
        $method = 'v' . str_replace('.', '_', $version);

        try {
            // Check if the method exists and call it
            if (method_exists($this, $method)) {
                $this->$method();
            }
        } catch (\Exception $e) {
            $this->debug("Migration {$version} failed: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Database version 1.0.0. Create the page table.
     *
     * @returns void
     */
    private function v1_0_0() {
        Page::create_table();
    }
}
