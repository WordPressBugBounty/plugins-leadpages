<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\http\exceptions\RequestException;

/**
 * Response code >400.
 */
class BadResponseException extends RequestException {
}
