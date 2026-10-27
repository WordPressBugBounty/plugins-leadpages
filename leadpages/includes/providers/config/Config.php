<?php

namespace Leadpages\providers\config;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * Config
 */
class Config {

    private static $me;

    /**
     * The store of config data
     */
    private $config;

    /**
     * Initialize plugin configuration based on the environment the plugin is running in.
     */
    private function __construct() {
        $override = defined('LEADPAGES_CONFIG_ENV') ? LEADPAGES_CONFIG_ENV : null;
        $this->config = $this->get_config(self::resolve_environment(wp_get_environment_type(), $override));
    }

    /**
     * Pick the config file for a site.
     *
     * Customer sites set WordPress's environment type to "local" too (LocalWP, a host's dev copy),
     * so "local" alone must not load local.config.php and its docker URLs. Only our own docker
     * setup reaches it, by defining LEADPAGES_CONFIG_ENV.
     *
     * @param string      $wp_environment - wp_get_environment_type()
     * @param string|null $override - LEADPAGES_CONFIG_ENV, when defined
     * @return string
     */
    public static function resolve_environment( $wp_environment, $override ) {
        if (null !== $override) {
            return $override;
        }
        if ('staging' === $wp_environment || 'development' === $wp_environment) {
            return 'development';
        }
        return 'production';
    }

    /**
     * Get singleton config class.
     *
     * @return Config
     */
    public static function get_instance() {
        return ! isset(self::$me) ? ( self::$me = new Config() ) : self::$me;
    }

    /**
     * Get a config value
     *
     * @param string $key
     * @return mixed
     */
    public function get( $key ) {
        if (array_key_exists($key, $this->config)) {
            return $this->config[ $key ];
        }

        return null;
    }

    /**
     * Set a config value
     *
     * @param string $key
     * @param mixed $value
     */
    public function set( $key, $value ) {
        $this->config[ $key ] = $value;
    }

    /**
     * Read the configuration file for the environment
     *
     * @param string $env - either production or docker
     * @return array
     */
    private function get_config( $env ) {
        $filename = __DIR__ . '/' . $env . '.config.php';
        return require_once $filename;
    }
}
