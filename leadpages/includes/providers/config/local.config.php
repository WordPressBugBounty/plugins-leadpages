<?php

namespace Leadpages\providers\config;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

return [
    'LEADPAGES_URL'    => 'http://leadpages.docker/',
    'BUILDER_URL'      => 'http://builder.leadpages.docker/',
    'ACCOUNT_API_URL'  => 'http://stargate.docker/account/v1/',
    'PAGES_API_URL'    => 'http://stargate.docker/content/v1/leadpages',
    'OAUTH2_CLIENT_ID' => 'not-set',
];
