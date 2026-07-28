<?php

namespace Leadpages\providers\config;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

return [
    'LEADPAGES_URL'    => 'https://my.leadpages.com/',
    'BUILDER_URL'      => 'https://pages.leadpages.com/',
    'ACCOUNT_API_URL'  => 'https://api.leadpages.io/account/v1/',
    'PAGES_API_URL'    => 'https://api.leadpages.io/content/v1/leadpages',
    'OAUTH2_CLIENT_ID' => '4PrRFNNQ6HZofeobzC67ES',

    // Nova (new Leadpages / htmlpub) backend.
    // NOVA_APP_URL is the origin hosting the Nova /api and /raw serving surfaces; NOVA_DASHBOARD_URL
    // is used for "Edit in Leadpages" deep links. Both are stored WITHOUT a trailing slash so paths
    // can be appended predictably (e.g. NOVA_APP_URL . '/api/oauth/token'). This matches the Nova
    // production origin (htmlpub leadpages-root NEXT_PUBLIC_APP_URL).
    // NOVA_OAUTH_CLIENT_ID is a PUBLIC PKCE client (no secret). The matching partnerOauthClients row
    // must be seeded in the htmlpub production DB before release (see drizzle/0152 in the backend PR).
    'NOVA_APP_URL'          => 'https://leadpages.com',
    'NOVA_DASHBOARD_URL'    => 'https://leadpages.com',
    'NOVA_OAUTH_CLIENT_ID'  => 'leadpages-wordpress',
];
