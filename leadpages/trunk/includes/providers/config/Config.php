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
        $environment = wp_get_environment_type();
        if ('staging' === $environment) {
            $environment = 'development';
        }
        $this->config = $this->get_config($environment);
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
