<?php

namespace Leadpages\providers\http\exceptions;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\http\exceptions\ClientException;

/**
* A 401 or 403 response.
*/
class AuthException extends ClientException {
}
