<?php

namespace Leadpages;

use Leadpages\providers\Utils;
use Leadpages\rest\Service;
use Leadpages\models\Page;
use Leadpages\providers\config\Config;
use Leadpages\models\Options;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * This is the core class of the plugin responsible for initializing all other dependencies
 * and running the plugin. All of the hooks the plugin depends on are added in this class.
 */
class Core {

    use Utils;

    /** @var Core $me the singleton instantiation of the plugin */
    private static $me;

    /** @var Activator $activator */
    private $activator;

    /** @var Assets $assets */
    private $assets;

    /** @var Service $service */
    private $service;

    /** @var Proxy $proxy */
    private $proxy;

    /** @var Popups $popups */
    private $popups;

    /** @var Config */
    private $config;

    /**
     * Instantiate all of the class dependencies.
     *
     * The constructor is protected because a factory method should only create
     * a Core object.
     */
    protected function __construct() {
        $this->config    = Config::get_instance();

        $this->activator = new Activator();
        $this->assets    = new Assets();
        $this->service   = new Service();
        $this->proxy     = new Proxy();
        $this->popups    = new Popups();
    }

    /**
     * Get singleton core class.
     *
     * @return Core
     */
    public static function get_instance() {
        return ! isset(self::$me) ? ( self::$me = new Core() ) : self::$me;
    }

    /**
     * Register all of the hooks for the plugin.
     */
    public function run() {
        add_action('init', [ $this->get_proxy(), 'serve_landing_page' ], 1);
        add_action('init', [ $this, 'init' ]);
        add_action('rest_api_init', [ $this->get_service(), 'rest_api_init' ]);

        add_action('plugins_loaded', [ $this->get_activator(), 'update_db_check' ]);
        add_action('plugins_loaded', [ $this, 'detect_plugin_conflicts' ]);
        register_deactivation_hook(LEADPAGES_FILE, [ $this->get_activator(), 'deactivate' ]);

        add_filter('wp_insert_post_data', [ $this, 'check_and_modify_post_slug' ], 1, 1);
        add_action('wp_enqueue_scripts', [ $this->get_popups(), 'inject_embed' ]);
        add_filter('script_loader_tag', [ $this->get_popups(), 'add_async_attribute' ], 10, 2);
        $this->setup_admin_notices();
    }

    /**
     * This method is fired on the WordPress init hook. It sets up the admin
     * menu items and enqueues the scripts we need for our admin pages to work.
     */
    public function init() {
        add_action('admin_menu', [ $this->get_assets(), 'render_admin_menu_page' ]);
        add_action('admin_menu', [ $this->get_assets(), 'render_oauth_complete_page' ]);
        add_action('admin_enqueue_scripts', [ $this->get_assets(), 'enqueue_admin_scripts' ]);

        if ($this->is_connected_to_plugin()) {
            add_action('admin_menu', [ $this->get_assets(), 'render_settings_page' ]);
        }
    }

    /**
     * Alert the user if:
     * - Permalinks are not enabled (i.e. "Plain")
     */
    private function setup_admin_notices() {
        if ('' === Options::get(Options::$permalink_structure)) {
            add_action('admin_notices', [ $this, 'turn_on_permalinks' ]);
        }
    }


    /**
     * Detect another active plugin that also serves Leadpages content (registers a
     * serve_landing_page handler on init at priority 1). To ensure two plugins never both take over
     * the request and exit(), stand our own proxy down and warn the administrator to keep only one
     * Leadpages plugin active.
     *
     * @return void
     */
    public function detect_plugin_conflicts() {
        if (! $this->has_conflicting_serve_hook()) {
            return;
        }

        // Stand down so only the other plugin serves. When the admin deactivates the duplicate (per
        // the notice below) the remaining plugin no longer sees a conflict and serves normally.
        remove_action('init', [ $this->get_proxy(), 'serve_landing_page' ], 1);
        add_action('admin_notices', [ $this, 'conflicting_plugin_notice' ]);
    }

    /**
     * Whether an object other than our own Proxy has registered serve_landing_page on init:1.
     *
     * @return bool
     */
    private function has_conflicting_serve_hook() {
        global $wp_filter;
        if (empty($wp_filter['init']) || empty($wp_filter['init']->callbacks[1])) {
            return false;
        }

        foreach ($wp_filter['init']->callbacks[1] as $callback) {
            $function = $callback['function'];
            if (
                is_array($function)
                && isset($function[1])
                && 'serve_landing_page' === $function[1]
                && $function[0] !== $this->get_proxy()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show an admin notice warning that another Leadpages plugin is active.
     */
    public function conflicting_plugin_notice() {
        echo wp_kses(
            "<div class='notice notice-warning is-dismissible'>
            <p> Another Leadpages plugin appears to be active. To avoid conflicts serving your pages,
                this Leadpages plugin has paused serving pages. Please keep only one Leadpages plugin
                active.
                </p></div>",
            [
                'div' => [
                    'class' => [],
                ],
                'p'   => [],
            ]
        );
    }

    /**
     * Show an admin notice informing the user that they need to enable permalinks
     */
    public function turn_on_permalinks() {
            echo wp_kses(
                "<div class='notice notice-error is-dismissible'>
                <p> Leadpages plugin needs
                    <a href='options-permalink.php'>permalinks</a> enabled!
                    Permalink structure can not be 'Plain'.
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

    /**
     * Check if Leadpages landing page is already connected with the same slug as the
     * name of the WordPress post. This would cause permalink conflicts when serving pages if not prevented.
     * Update the post name to a unique value across the posts and Leadpages tables.
     *
     * @param array $data an array of slashed, sanitized, and processed wp post data
     * @return array
     */
    public function check_and_modify_post_slug( $data ) {
        $slug = $data['post_name'];
        $conflicts = Page::get_by_slug($slug);

        // if a conflict is found, modify the slug by adding a numbered suffix and repeat until there is no conflict
        if ($conflicts) {
            $suffix = 2;
            do {
                $new_slug = $slug . '-' . $suffix;
                ++$suffix;
                $this->debug("Conflict with $slug, changing post name to $new_slug");
                $conflicts = Page::get_by_slug($new_slug);
            } while ($conflicts);

            $data['post_name'] = $new_slug;
        }

        return $data;
    }

    /** @return Activator  */
    public function get_activator() {
        return $this->activator;
    }

    /** @return Assets  */
    public function get_assets() {
        return $this->assets;
    }

    /** @return Service  */
    public function get_service() {
        return $this->service;
    }

    /** @return Proxy  */
    public function get_proxy() {
        return $this->proxy;
    }

    /** @return Popups  */
    public function get_popups() {
        return $this->popups;
    }
}
