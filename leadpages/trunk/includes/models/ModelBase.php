<?php

namespace Leadpages\models;

defined('ABSPATH') || die('No script kiddies please!');

use Leadpages\models\exceptions\DatabaseError;

/**
 * Base class for all Leadpages models. This class provides useful methods to
 * access the database and get the table name for the model.
 */
class ModelBase {

    /**
     * Throw an exception if there is a database error with the last query
     *
     * @return void
     * @throws DatabaseError
     */
    public static function throw_on_db_error() {
        global $wpdb;
        $message = $wpdb->last_error;
        if ('' !== $message) {
            throw new DatabaseError(esc_html($message));
        }
    }
}
