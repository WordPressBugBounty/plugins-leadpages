<?php

namespace Leadpages\providers\config;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

return [
    'LEADPAGES_URL'    => 'http://leadpages.docker/',
    'BUILDER_URL'      => 'http://builder.leadpages.docker/',
    'ACCOUNT_API_URL'  => 'http://stargate.docker/account/v1/',
    'PAGES_API_URL'    => 'http://stargate.docker/content/v1/leadpages',
    'OAUTH2_CLIENT_ID' => 'not-set',

    // Nova (new Leadpages / htmlpub) backend for local end-to-end testing.
    // Stored WITHOUT a trailing slash. The Nova dev server (`npm run dev:nova`)
    // listens on port 3001. WordPress runs inside the wp-env Docker container,
    // so it reaches the host via `host.docker.internal` (add
    // `127.0.0.1 host.docker.internal` to /etc/hosts so the browser resolves the
    // same origin for the OAuth popup). See HP-2528 local testing notes.
    'NOVA_APP_URL'          => 'http://host.docker.internal:3001',
    'NOVA_DASHBOARD_URL'    => 'http://host.docker.internal:3001',
    'NOVA_OAUTH_CLIENT_ID'  => 'leadpages-wordpress',
];
