<?php

namespace Leadpages\rest\oauth2;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\models\Options;
use Leadpages\providers\http\Client;
use Leadpages\providers\config\Config;
use Leadpages\providers\http\exceptions\HTTPException;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\Utils;
use WP_Error;

use const Leadpages\SLUG_OAUTH_COMPLETE;

/**
 * WordPress controller class for the leadpages/v1/oauth2 collection responsible
 * for receiving the authorization code from the Leadpages OAuth2 sign in flow and
 * completing the sign in flow within the plugin.
 */
class Controller {

    use Utils;

    /** @var Config */
    private $config;
    /** @var Client */
    private $client;

    /**
     * REST route information for the controller
     */
    private $version = '1';
    private $namespace;
    private $base = '/oauth2';

    public function __construct() {
        $this->config = Config::get_instance();
        $this->client = new Client();
        $this->namespace = LEADPAGES_NS . '/v' . $this->version;
    }

    /**
    * Register the routes for the Leadpages auth controller
    *
    * GET /leadpages/v1/oauth2 - handles response from oauth2 sign in flow
    */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            $this->base,
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'handle_oauth2_signin' ],
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
                    'callback'            => [ $this, 'authorize_oauth2' ],
                    'permission_callback' => [ $this, 'signin_callback_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/status',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_oauth2_status' ],
                    'permission_callback' => [ $this, 'get_oauth2_status_permissions_check' ],
                ],
            ]
        );
        register_rest_route(
            $this->namespace,
            $this->base . '/sign-out',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'handle_sign_out' ],
                    'permission_callback' => [ $this, 'sign_out_callback_permissions_check' ],
                ],
            ]
        );
    }

    /**
     * Generate a random code_verifier for the OAuth2 sign in flow
     */
    private function generate_code_verifier() {
        $length = 43; // Length can be between 43 and 128
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $characters_length = strlen($characters);
        $random_string = '';
        for ($i = 0; $i < $length; $i++) {
            $random_string .= $characters[ random_int(0, $characters_length - 1) ];
        }
        return $random_string;
    }

    /**
     * Generate a code_challenge from the code_verifier using SHA256
     */
    private function generate_code_challenge( $code_verifier ) {
        $hash = hash('sha256', $code_verifier, true);
        $code_challenge = strtr(base64_encode($hash), '+/', '-_');
        // Remove any padding equal signs
        $code_challenge = str_replace('=', '', $code_challenge);

        return $code_challenge;
    }

    /**
     * Check if the user has permission to access the sign in callback
     * @return bool
     */
    public function signin_callback_permissions_check() {
        return true;
    }

    /**
     * Handle the authorization of the OAuth2 sign in flow. This endpoint will create a
     * code_verifier and code_challenge, and return the a URL to direct a user to for login.
     */
    public function authorize_oauth2() {
        // Since our login flow involves directing the browser to different endpoints registered with WordPress,
        // it will not work if the user is using a "plain" permalink structure. We have to guard against this
        // and show a better error message in the UI than they would otherwise receive.
        if (Options::get(Options::$permalink_structure) === '') {
            return new WP_Error(
                'permalinks_error',
                "Your permalink structure can not be 'Plain'",
                [ 'status' => 400 ]
            );
        }

        $code_verifier = $this->generate_code_verifier();
        $code_challenge = $this->generate_code_challenge($code_verifier);

        $login_url  = $this->config->get('LEADPAGES_URL') . 'oauth2-login?' . http_build_query([
            'client_id'      => $this->config->get('OAUTH2_CLIENT_ID'),
            'redirect_uri'   => home_url('/wp-json/' . $this->namespace . $this->base),
            'scope'          => 'content-r',
            'code_challenge' => $code_challenge,
        ]);

        // Save the code_verifier locally
        Options::set(Options::$code_verifier, $code_verifier);

        return new \WP_REST_Response($login_url);
    }

    /**
    * Handle the response from oauth2 sign in flow. This is the endpoint that should be specified as
    * the "redirect_uri" when opening the OAuth2 sign in page.
    *
    * @param WP_REST_Request $request
    * @return void redirects to leadpages admin page
    */
    public function handle_oauth2_signin( $request ) {
        $base_redirect_path = 'admin.php?page=' . SLUG_OAUTH_COMPLETE;

        $query = $request->get_query_params();
        $code = $query['code'] ?? null;

        // The authorization request can be received here without a code in the query string. In this
        // case, there *should* be an error param in the query but WordPress is stripping it out
        // so we don't see it here. Instead, we will treat the absence of the code as an error
        // and redirect with our own error param (lperror) to show messaging to the user in the UI
        if (! $code) {
            $this->debug('No code sent in the authorization request, aborting');
            wp_safe_redirect(admin_url($base_redirect_path . '&lperror=access_denied'));
            $this->lp_exit();
            return; // return for tests as lp_exit is mocked to do nothing
        }

        $code_verifier = Options::get(Options::$code_verifier);

        $data = $this->fetch_access_token($code, $code_verifier);
        if (! $data) {
            wp_safe_redirect(admin_url($base_redirect_path . '&lperror=invalid_code'));
            $this->lp_exit();
            return; // return for tests as lp_exit is mocked to do nothing
        }

        Options::set(Options::$refresh_token, $data['refresh_token']);
        Options::set(Options::$access_token, $data['access_token']);

        $this->debug('Successfully authorized the user');
        wp_safe_redirect(admin_url($base_redirect_path));

        // Remove code_verifier after successful login
        Options::delete(Options::$code_verifier);

        $this->lp_exit();
    }

    /**
     * Check if the user has permission to access the get oauth2 status endpoint
     * @return bool
     */
    public function get_oauth2_status_permissions_check() {
        return true;
    }

    /**
     * Return the logged in status of the user
     * @return \WP_REST_Response
     */
    public function get_oauth2_status() {
        return new \WP_REST_Response([
            'isLoggedIn' => $this->is_user_logged_into_plugin(),
        ]);
    }

    /**
     * Handle user sign-out by deleting refresh token and access token options.
     */
    public function handle_sign_out() {
        Options::delete(Options::$refresh_token);
        Options::delete(Options::$access_token);

        return new \WP_REST_Response(null, 204);
    }

    /**
     * Check if the user has permission to access to the signout callback
     */
    public function sign_out_callback_permissions_check() {
        return true;
    }

    /**
     * Retrieve the access token from the Leadpages API
     *
     * @param string $code
     * @param bool $retry whether to retry the request on 500 errors
     * @return array|null
     */
    private function fetch_access_token( $code, $code_verifier, $retry = true ) {
        try {
            $response = $this->client->post(
                $this->config->get('ACCOUNT_API_URL') . 'oauth2/access-tokens',
                [
                    'body'    => [
                        'code'          => $code,
                        'code_verifier' => $code_verifier,
                        'client_id'     => $this->config->get('OAUTH2_CLIENT_ID'),
                        'grant_type'    => 'authorization_code',
                        'redirect_uri'  => home_url() . '/wp-json/' . $this->namespace . $this->base,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                ]
            );

            return json_decode($response['body'], true);
        } catch (ServerException $e) {
            if ($retry) {
                $this->debug('Retrying access token request');
                return $this->fetch_access_token($code, $code_verifier, false);
            }
            $this->debug('Failed to get access token');
            return null;
        } catch (HTTPException $e) {
            $this->debug('Failed to get access token');
            return null;
        }
    }
}
