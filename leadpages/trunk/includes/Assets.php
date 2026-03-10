<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\config\Config;
use Leadpages\providers\Utils;

// All of the names of the assets the plugin uses.
// Values must match those configured in webpack
const ASSET_LANDINGPAGES = 'landingpages';
const ASSET_SETTINGS = 'lp_settings';
// Names of the pages the plugin uses
const SLUG_LEADPAGES = 'leadpages';
const SLUG_SETTINGS = 'lp_settings';
const SLUG_OAUTH_COMPLETE = 'lp_login_complete';

/**
 * Base asset management class for frontend scripts and styles.
 */
class Assets {

    use Utils;

    /** @var Config */
    public $config;

    /**
     * Path to the build and assets directories
     */
    public $build_path = 'build/';
    public $assets_path = 'public/';

    /**
     * Array of arrays containing file paths to each type of asset (js, css, php)
     * @var array
     */
    private $file_paths;

    public function __construct() {
        $this->config = Config::get_instance();

        $assets = $this->get_asset_names();
        foreach ($assets as $asset) {
            $base_path = $this->build_path . $asset;
            $this->file_paths[ $asset ] = [
                'js'   => $base_path . '.js',
                'css'  => $base_path . '.css',
                'php'  => $base_path . '.asset.php',
                'name' => LEADPAGES_SLUG . '_' . $asset,
            ];
        }
    }

    /**
     * Return all the names of all the assets that the plugin uses
     * @return array
     */
    private function get_asset_names() {
        return [
            ASSET_LANDINGPAGES,
            ASSET_SETTINGS,
            // add more assets as needed...
        ];
    }

    /**
     * Wrapper around include construct for testing
     *
     * @param string $path
     * @return mixed
     */
    public function get_asset_file( $path ) {
        return include LEADPAGES_PATH . '/' . $path;
    }

    /**
     * Enqueue scripts and styles for admin pages.
     *
     * @param string $hook_suffix The current admin page
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        $this->enqueue_landing_pages_page($hook_suffix);
        $this->enqueue_settings_page($hook_suffix);
        $this->enqueue_leadpages_icons();
        // add more scripts as needed...
    }

    /**
     * Render the Landing Pages page (also the admin menu page)
     *
     * This method adds a menu page for the Leadpages plugin in the WordPress admin dashboard.
     * The page is simply a div that a React component will attach to from the script enqueue'd
     * in the `enqueue_admin_scripts` method.
     */
    public function render_admin_menu_page() {
        add_menu_page(
            'Leadpages', // Page title
            'Leadpages', // Menu title
            'manage_options', // Capability
            SLUG_LEADPAGES,
            function () {
                echo '
                <div id="leadpages-page-root"></div>
                ';
            },
            null, // The icon will be added through CSS
            80 // Positions menu under the "Settings" sidebar
        );

        // Top-level menu item (Leadpages) differ from the first sub-level item (Landing Pages)
        // NOTE: This submenu is not visible in the UI navigation menu until there's been more
        // than one submenu added (ex. Settings). This is a WordPress quirk. Adding this in now
        // though for future development.
        add_submenu_page(
            SLUG_LEADPAGES, // Parent slug
            'Landing Pages', // Page title
            'Landing Pages', // Menu title
            'manage_options', // Capability
            SLUG_LEADPAGES,
            function () {
                echo '
                <div id="leadpages-page-root"></div>
                ';
            }
        );
    }

    public function render_settings_page() {
        add_submenu_page(
            SLUG_LEADPAGES, // Parent slug
            'Settings', // Page title
            'Settings', // Menu title
            'manage_options', // Capability
            SLUG_SETTINGS, // Menu slug
            function () {
                echo '
                <div id="settings-page-root"></div>
                ';
            }
        );
    }

    /**
     * Render the OAuth2 completion page without a menu item. This page should only be seen within
     * the popup window that the Leadpages OAuth login is displayed in.
     *
     * @see https://stackoverflow.com/questions/3902760/how-do-you-add-a-wordpress-admin-page-without-adding-it-to-the-menu/47577455#47577455
     */
    public function render_oauth_complete_page() {
        add_submenu_page(
            '', // By setting the parent slug to an empty string, it should not be visible in the menu
            'Login Complete',
            'Login Complete',
            'manage_options',
            SLUG_OAUTH_COMPLETE,
            function () {
                include_once LEADPAGES_PATH . '/includes/other/login_complete_page.php';
            }
        );
    }

    /**
     * Enqueue scripts and styles for the Landing Pages page.
     * This method loads the scripts and styles only on the Leadpages Landing Pages page.
     *
     * @param string $hook The current admin page
     */
    private function enqueue_landing_pages_page( $hook ) {
        // Load only on ?page=leadpages (Leadpages page)
        if ('toplevel_page_' . SLUG_LEADPAGES !== $hook) {
            return;
        }

        // Automatically load imported dependencies and assets version.
        $landing_pages_asset_file = $this->get_asset_file($this->file_paths[ ASSET_LANDINGPAGES ]['php']);

        // Load JavaScript
        wp_enqueue_script(
            $this->file_paths[ ASSET_LANDINGPAGES ]['name'],
            plugins_url($this->file_paths[ ASSET_LANDINGPAGES ]['js'], LEADPAGES_FILE),
            $landing_pages_asset_file['dependencies'],
            $landing_pages_asset_file['version'],
            true
        );

        // leverage this to inject additional data into the script
        // these are used to conditionally link out to analytics based on environment configuration
        wp_localize_script(
            $this->file_paths[ ASSET_LANDINGPAGES ]['name'],
            LEADPAGES_NS . 'Data',
            [
                'homeUrl'      => home_url(),
                'leadpagesUrl' => $this->config->get('LEADPAGES_URL'),
                'builderUrl'   => $this->config->get('BUILDER_URL'),
            ]
        );

        // Load CSS
        wp_enqueue_style(
            $this->file_paths[ ASSET_LANDINGPAGES ]['name'],
            plugins_url($this->file_paths[ ASSET_LANDINGPAGES ]['css'], LEADPAGES_FILE),
            [ 'wp-components' ],
            $landing_pages_asset_file['version']
        );
    }

    /**
     * Enqueue scripts and styles for the Settings page.
     * This method loads the scripts and styles only on the Leadpages Settings page.
     *
     * @param string $hook The current admin page
     */
    private function enqueue_settings_page( $hook ) {
        // Load only on ?page=settings (Settings page)
        if ('leadpages_page_' . SLUG_SETTINGS !== $hook) {
            return;
        }

        // Automatically load imported dependencies and assets version.
        $settings_asset_file = $this->get_asset_file($this->file_paths[ ASSET_SETTINGS ]['php']);

        // Load JavaScript
        wp_enqueue_script(
            $this->file_paths[ ASSET_SETTINGS ]['name'],
            plugins_url($this->file_paths[ ASSET_SETTINGS ]['js'], LEADPAGES_FILE),
            $settings_asset_file['dependencies'],
            $settings_asset_file['version'],
            true
        );

        // Load CSS
        wp_enqueue_style(
            $this->file_paths[ ASSET_SETTINGS ]['name'],
            plugins_url($this->file_paths[ ASSET_SETTINGS ]['css'], LEADPAGES_FILE),
            [ 'wp-components' ],
            $settings_asset_file['version']
        );
    }

    /*
     * Enqueue the Leadpages icons. The LP icon in the top level menu item is dependent on this.
     */
    private function enqueue_leadpages_icons() {
        wp_enqueue_style(
            'leadpages-icons',
            plugins_url('public/lp-icons.css', LEADPAGES_FILE),
            [],
            LEADPAGES_VERSION
        );
    }
}
