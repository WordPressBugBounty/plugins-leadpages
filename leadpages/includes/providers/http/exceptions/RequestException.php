<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\http\exceptions\HttpException;

/**
 * Base exception for successful requests with response errors.
 */
class RequestException extends HttpException {
}
