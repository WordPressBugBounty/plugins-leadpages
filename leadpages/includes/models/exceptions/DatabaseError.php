<?php

namespace Leadpages\models\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;

/**
 * This is the base exception class for database errors
 */
class DatabaseError extends \Exception {

    use Utils;

    /**
     * Log the debug message and construct the Exception class with a default message
     *
     * @param string $message
     * @return void
     */
    public function __construct( $message ) {
        $error = "Database operation failed: $message";
        $this->debug($error);
        parent::__construct($error);
    }
}
