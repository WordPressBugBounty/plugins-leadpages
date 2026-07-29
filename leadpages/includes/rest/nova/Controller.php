<?php

namespace Leadpages\rest\nova;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\models\Options;
use Leadpages\models\Page;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\auth\OAuthAuthProvider;
use Leadpages\providers\config\Config;
use Leadpages\providers\http\exceptions\HttpException;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\Utils;
use WP_Error;

use const Leadpages\SLUG_OAUTH_COMPLETE;

/**
 * WordPress controller for connecting a Nova (new Leadpages) account through OAuth 2.0.
 *
 * The flow mirrors the Classic oauth2 controller (PKCE authorization code + popup +
 * BroadcastChannel completion page); only the authorize/token URLs, client id, and scope differ and
 * all come from config. The routes live under leadpages/v1/nova so they never collide with the
 * Classic oauth2 routes registered on the same namespace.
 *
 *   GET    /leadpages/v1/nova/oauth2      - OAuth redirect target (receives the authorization code)
 *   GET    /leadpages/v1/nova/authorize   - begin connect; returns the Nova authorize URL
 *   GET    /leadpages/v1/nova/status      - connection status (boolean, never the token)
 *   DELETE /leadpages/v1/nova/disconnect  - erase the stored Nova credentials
 */
class Controller {

    use Utils;

    /** @var Config */
    private $config;

    /**
     * REST route information for the controller
     */
    private $version = '1';
    private $namespace;
    private $base = '/nova';

    /** The OAuth scope requested for the Nova connection */
    private $scope = 'pages:read';

    public function __construct() {
        $this->config = Config::get_instance();
        $this->namespace = LEADPAGES_NS . '/v' . $this->version;
    }

    /**
     * Register the routes for the Nova connect controller.
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            $this->base . '/oauth2',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'handle_nova_signin' ],
                    'permission_callback' => [ $this, 'signin_callback_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/authorize',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'authorize_nova' ],
                    'permission_callback' => [ $this, 'manage_options_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/status',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_status' ],
                    'permission_callback' => [ $this, 'manage_options_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/disconnect',
            [
                [
                    'methods'             => 'DELETE',
                    'callback'            => [ $this, 'disconnect' ],
                    'permission_callback' => [ $this, 'manage_options_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/popups',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_popups' ],
                    'permission_callback' => [ $this, 'manage_options_permissions_check' ],
                ],
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'save_popup' ],
                    'permission_callback' => [ $this, 'manage_options_permissions_check' ],
                    'args'                => [
                        'popupId' => [
                            'description'       => 'The id of the Nova pop-up to embed site-wide, or null to disable.',
                            'type'              => [ 'string', 'null' ],
                            'sanitize_callback' => function ( $param ) {
                                return is_null($param) ? null : sanitize_text_field($param);
                            },
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check for the endpoints an administrator drives from wp-admin. WordPress validates
     * the REST cookie nonce before this runs for cookie-authenticated requests.
     *
     * @return bool
     */
    public function manage_options_permissions_check() {
        return current_user_can('manage_options');
    }

    /**
     * Permission check for the OAuth redirect target.
     *
     * This intentionally allows unauthenticated access because it is the OAuth redirect_uri: Nova
     * redirects the browser here after consent. The state parameter (verified in the handler)
     * protects the callback against forged requests.
     *
     * @return bool
     */
    public function signin_callback_permissions_check() {
        return true;
    }

    /**
     * Begin the Nova connect flow. Creates a PKCE code_verifier/code_challenge and a state value,
     * stores them for the callback, and returns the Nova authorize URL for the popup to open.
     *
     * @return WP_Error|\WP_REST_Response
     */
    public function authorize_nova() {
        // The redirect target is a WordPress REST route, so a "Plain" permalink structure breaks it.
        if ('' === Options::get(Options::$permalink_structure)) {
            return new WP_Error(
                'permalinks_error',
                "Your permalink structure can not be 'Plain'",
                [ 'status' => 400 ]
            );
        }

        $code_verifier = $this->generate_code_verifier();
        $code_challenge = $this->generate_code_challenge($code_verifier);
        $state = $this->generate_state();

        // Consent page lives at /oauth/authorize/consent (3 path segments); the
        // 2-segment /oauth/authorize is caught by the platform's legacy
        // page-serving pattern and 404s. See the consent page.tsx header comment.
        $authorize_url = $this->config->get('NOVA_APP_URL') . '/oauth/authorize/consent?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->config->get('NOVA_OAUTH_CLIENT_ID'),
            'redirect_uri'          => $this->redirect_uri(),
            'scope'                 => $this->scope,
            'state'                 => $state,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
        ]);

        Options::set(Options::$nova_code_verifier, $code_verifier);
        Options::set(Options::$nova_oauth_state, $state);

        return new \WP_REST_Response($authorize_url);
    }

    /**
     * Handle the Nova OAuth redirect. Verifies the state, exchanges the authorization code for
     * tokens, stores the credentials, and redirects the popup to the completion page which
     * broadcasts the result to the opening window.
     *
     * @param \WP_REST_Request $request
     * @return void redirects to the leadpages OAuth completion admin page
     */
    public function handle_nova_signin( $request ) {
        $base_redirect_path = 'admin.php?page=' . SLUG_OAUTH_COMPLETE;

        $query = $request->get_query_params();
        $code = isset($query['code']) ? sanitize_text_field($query['code']) : null;
        $state = isset($query['state']) ? sanitize_text_field($query['state']) : null;

        $expected_state = Options::get(Options::$nova_oauth_state);
        // A missing or mismatched state indicates a forged or stale callback; reject it.
        if (empty($expected_state) || ! hash_equals((string) $expected_state, (string) $state)) {
            $this->debug('Nova OAuth state mismatch, aborting');
            $this->clear_transient_flow_state();
            wp_safe_redirect(admin_url($base_redirect_path . '&lperror=access_denied'));
            $this->lp_exit();
            return; // return for tests as lp_exit is mocked to do nothing
        }

        // WordPress strips the error param from the callback, so treat a missing code as denial.
        if (! $code) {
            $this->debug('No code sent in the Nova authorization request, aborting');
            $this->clear_transient_flow_state();
            wp_safe_redirect(admin_url($base_redirect_path . '&lperror=access_denied'));
            $this->lp_exit();
            return; // return for tests as lp_exit is mocked to do nothing
        }

        $code_verifier = Options::get(Options::$nova_code_verifier);
        $data = $this->fetch_access_token($code, $code_verifier);
        if (! $data || empty($data['access_token'])) {
            $this->clear_transient_flow_state();
            wp_safe_redirect(admin_url($base_redirect_path . '&lperror=invalid_code'));
            $this->lp_exit();
            return; // return for tests as lp_exit is mocked to do nothing
        }

        Options::set(Options::$nova_access_token, $data['access_token']);
        if (! empty($data['refresh_token'])) {
            Options::set(Options::$nova_refresh_token, $data['refresh_token']);
        }
        Options::set(Options::$platform, 'nova');

        // The token is org-scoped by the backend. If the token response also names the organization
        // (used for "Edit in Leadpages" deep links) store it; otherwise leave it unset.
        // TODO(HP-2528): confirm with the backend whether/where the organization id is returned.
        $org_id = $this->extract_org_id($data);
        if ($org_id) {
            Options::set(Options::$nova_org_id, $org_id);
        }

        $this->clear_transient_flow_state();
        $this->debug('Successfully connected the Nova account');
        wp_safe_redirect(admin_url($base_redirect_path));
        $this->lp_exit();
    }

    /**
     * Return the Nova connection status. Never returns the token itself.
     *
     * When the request carries ?verify=1 this also makes a single authenticated call to the Nova
     * pages endpoint to confirm the stored credentials are usable (credentialsValid).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_status( $request ) {
        $is_connected = $this->is_connected_to_nova();

        $body = [
            'isConnected' => $is_connected,
            'platform'    => 'nova',
        ];

        if ($is_connected && rest_sanitize_boolean($request->get_param('verify'))) {
            $body['credentialsValid'] = $this->verify_pages_access();
        }

        return new \WP_REST_Response($body);
    }

    /**
     * Erase the stored Nova credentials and connection state.
     *
     * @return \WP_REST_Response
     */
    public function disconnect() {
        Options::delete(Options::$nova_access_token);
        Options::delete(Options::$nova_refresh_token);
        Options::delete(Options::$nova_org_id);
        Options::delete(Options::$platform);
        Options::delete(Options::$nova_popup_id);
        $this->clear_transient_flow_state();

        // Drop this account's unpublished catalog so it does not linger for the next connection.
        // Published pages (connected = 1) are left intact so live pages keep serving.
        Page::delete_catalog_by_platform('nova');

        return new \WP_REST_Response(null, 204);
    }

    /**
     * Return the org's Nova pop-ups plus the currently selected pop-up id. Degrades gracefully to
     * disabled/empty so the UI can hide the feature when pop-ups are unavailable.
     *
     * @return \WP_REST_Response
     */
    public function get_popups() {
        $enabled = false;
        $popups = [];

        // Only reach out to Nova when actually connected to it; otherwise report the feature as off.
        if (! $this->is_connected_to_nova()) {
            return new \WP_REST_Response([
                'enabled'    => false,
                'popups'     => [],
                'selectedId' => null,
            ]);
        }

        try {
            $client = new Client(OAuthAuthProvider::for_nova());
            $response = $client->get(
                $this->config->get('NOVA_APP_URL') . '/api/popups',
                [ 'timeout' => 10 ]
            );
            $body = json_decode($response['body']);
            $enabled = isset($body->enabled) ? (bool) $body->enabled : false;
            $popups = isset($body->popups) && is_array($body->popups) ? $body->popups : [];
        } catch (HttpException $e) {
            $this->debug('Nova pop-ups unavailable, hiding the feature');
        }

        $selected_id = Options::get(Options::$nova_popup_id);

        return new \WP_REST_Response([
            'enabled'    => $enabled,
            'popups'     => $popups,
            'selectedId' => $selected_id ? $selected_id : null,
        ]);
    }

    /**
     * Save (or clear) the Nova pop-up that should be embedded site-wide.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function save_popup( $request ) {
        $popup_id = $request->get_param('popupId');

        if (empty($popup_id)) {
            Options::delete(Options::$nova_popup_id);
        } else {
            Options::set(Options::$nova_popup_id, $popup_id);
        }

        return new \WP_REST_Response(null, 204);
    }

    /**
     * Whether both Nova tokens are present, regardless of whether they are expired.
     *
     * @return bool
     */
    private function is_connected_to_nova() {
        return ! empty(Options::get(Options::$nova_access_token))
            && ! empty(Options::get(Options::$nova_refresh_token));
    }

    /**
     * Make a single authenticated call to the Nova pages endpoint to confirm the credentials work.
     *
     * @return bool
     */
    private function verify_pages_access() {
        $client = new Client(OAuthAuthProvider::for_nova());
        try {
            $client->get(
                $this->config->get('NOVA_APP_URL') . '/api/pages',
                [
                    'query'   => [ 'published' => 'true' ],
                    'timeout' => 10,
                ]
            );
            return true;
        } catch (HttpException $e) {
            $this->debug('Nova credential verification failed');
            return false;
        }
    }

    /**
     * Exchange an authorization code for Nova tokens using PKCE (no client secret).
     *
     * @param string $code
     * @param string $code_verifier
     * @param bool $retry whether to retry the request on 500 errors
     * @return array|null the decoded token response or null on failure
     */
    private function fetch_access_token( $code, $code_verifier, $retry = true ) {
        // The token request is unauthenticated (PKCE), so it uses a client with no auth provider.
        $client = new Client();
        try {
            $response = $client->post(
                $this->config->get('NOVA_APP_URL') . '/api/oauth/token',
                [
                    'body'    => [
                        'grant_type'    => 'authorization_code',
                        'code'          => $code,
                        'redirect_uri'  => $this->redirect_uri(),
                        'client_id'     => $this->config->get('NOVA_OAUTH_CLIENT_ID'),
                        'code_verifier' => $code_verifier,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                ]
            );

            return json_decode($response['body'], true);
        } catch (ServerException $e) {
            if ($retry) {
                $this->debug('Retrying Nova access token request');
                return $this->fetch_access_token($code, $code_verifier, false);
            }
            $this->debug('Failed to get Nova access token');
            return null;
        } catch (HttpException $e) {
            $this->debug('Failed to get Nova access token');
            return null;
        }
    }

    /**
     * The per-site OAuth redirect target. The backend whitelists this path for the Nova client.
     *
     * @return string
     */
    private function redirect_uri() {
        return home_url('/wp-json/' . $this->namespace . $this->base . '/oauth2');
    }

    /**
     * Read the organization id from a token response under any of the plausible keys.
     *
     * @param array $data
     * @return string|null
     */
    private function extract_org_id( $data ) {
        foreach ([ 'organization_id', 'organizationId', 'org_id' ] as $key) {
            if (! empty($data[ $key ])) {
                return $data[ $key ];
            }
        }
        return null;
    }

    /**
     * Remove the transient values used only during the connect flow.
     */
    private function clear_transient_flow_state() {
        Options::delete(Options::$nova_code_verifier);
        Options::delete(Options::$nova_oauth_state);
    }

    /**
     * Generate a random code_verifier for the OAuth2 PKCE flow.
     *
     * @return string
     */
    private function generate_code_verifier() {
        $length = 43; // Length can be between 43 and 128
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $characters_length = strlen($characters);
        $random_string = '';
        for ($index = 0; $index < $length; $index++) {
            $random_string .= $characters[ random_int(0, $characters_length - 1) ];
        }
        return $random_string;
    }

    /**
     * Generate a code_challenge from the code_verifier using SHA256 (S256).
     *
     * @param string $code_verifier
     * @return string
     */
    private function generate_code_challenge( $code_verifier ) {
        $hash = hash('sha256', $code_verifier, true);
        $code_challenge = strtr(base64_encode($hash), '+/', '-_');
        // Remove any padding equal signs
        $code_challenge = str_replace('=', '', $code_challenge);

        return $code_challenge;
    }

    /**
     * Generate a random state value used to protect the OAuth callback against forgery.
     *
     * @return string
     */
    private function generate_state() {
        return wp_generate_password(32, false);
    }
}
