<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;

/**
 * This is the base exception class that handles HTTP errors encountered in Client.
 */
class HttpException extends \RuntimeException {

    use Utils;

    public $response;

    /**
     * Processes the response, extracts the error message and code, and logs the error if debugging is enabled.
     *
     * @param WP_HTTP_Response $response
     * @return void
     */
    public function __construct( $response ) {
        if (is_wp_error($response)) {
            $code = is_int($response->get_error_code()) ? $response->get_error_code() : null;
            $message = "Error $code: " . $response->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if (! is_int($code)) {
                $code = null;
            }
            $message = "Failed request [{$code}]: " . substr(wp_remote_retrieve_body($response), 0, 1000);
        }

        $this->debug($message);

        parent::__construct($message);
        $this->response = $response;
    }

    /**
     * Return the error message
     */
    public function __toString() {
        return __CLASS__ . ': ' . $this->message;
    }
}
