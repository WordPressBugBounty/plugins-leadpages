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
        register_deactivation_hook(LEADPAGES_FILE, [ $this->get_activator(), 'deactivate' ]);

        add_filter('wp_insert_post_data', [ $this, 'check_and_modify_post_slug' ], 1, 1);
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

        if ($this->is_user_logged_into_plugin()) {
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
}
