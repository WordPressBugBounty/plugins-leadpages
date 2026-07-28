<?php

namespace Leadpages\providers\config;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

return [
    'LEADPAGES_URL'    => 'https://my.leadpagestest.com/',
    'BUILDER_URL'      => 'https://pages.leadpagestest.com/',
    'ACCOUNT_API_URL'  => 'https://api-test.leadpages.io/account/v1/',
    'PAGES_API_URL'    => 'https://api-test.leadpages.io/content/v1/leadpages',
    'OAUTH2_CLIENT_ID' => 'umiUUQDm9bQZUYSjHnQSNe',

    // Nova (new Leadpages / htmlpub) backend. Stored WITHOUT a trailing slash.
    // Nova (htmlpub) runs only Local and Production environments — there is no
    // Nova staging origin. So development/staging WordPress installs (the ones
    // routed here) talk to the same production Nova origin as production.config;
    // a staging WP site connects its real Leadpages account and serves its real
    // published pages. (Classic keeps its dedicated *.leadpagestest.com env above.)
    'NOVA_APP_URL'          => 'https://leadpages.com',
    'NOVA_DASHBOARD_URL'    => 'https://leadpages.com',
    'NOVA_OAUTH_CLIENT_ID'  => 'leadpages-wordpress',
];
