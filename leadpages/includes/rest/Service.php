<?php

namespace Leadpages\rest;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\rest\pages\Controller as PagesController;
use Leadpages\rest\oauth2\Controller as OAuth2Controller;
use Leadpages\rest\nova\Controller as NovaController;

/**
 * A class to manage the /leadpages REST API endpoints within the WordPress.
 */
class Service {

    /** @var PagesController */
    private $pages_controller;

    /** @var OAuth2Controller */
    private $oauth2_controller;

    /** @var NovaController */
    private $nova_controller;

    /**
     * Initialize REST Service controller classes.
     */
    public function __construct() {
        $this->pages_controller = new PagesController();
        $this->oauth2_controller = new OAuth2Controller();
        $this->nova_controller = new NovaController();
        // add more controllers here
    }

    /**
     * Register routes for every REST controller class.
     */
    public function rest_api_init() {
        $this->pages_controller->register_routes();
        $this->oauth2_controller->register_routes();
        $this->nova_controller->register_routes();
    }
}
