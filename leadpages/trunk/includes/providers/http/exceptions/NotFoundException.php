<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\http\exceptions\BadResponseException;

/**
 * A 404 response.
 */
class NotFoundException extends BadResponseException {
}
