<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\http\exceptions\HttpException;

/**
 * A failure while making the request.
 */
class RequestFailureException extends HttpException {
}
