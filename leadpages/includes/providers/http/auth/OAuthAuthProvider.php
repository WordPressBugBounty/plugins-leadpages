<?php

namespace Leadpages\providers\http\auth;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\models\Options;
use Leadpages\providers\Utils;
use Leadpages\providers\config\Config;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\http\exceptions\HttpException;

/**
 * An AuthProvider for OAuth 2.0 bearer-token backends. It attaches the stored access token as a
 * bearer header and, on an auth failure, refreshes the access token with the stored refresh token
 * (clearing both tokens when the refresh cannot succeed).
 *
 * Both the Classic and Nova (new Leadpages) backends are OAuth, so this single provider serves both
 * via the for_classic()/for_nova() factories which supply the backend-specific token endpoint,
 * client id, and option keys. This is the refresh-on-401/403 behavior that previously lived inline
 * in the http Client, extracted so the Client stays backend-agnostic.
 */
class OAuthAuthProvider implements AuthProvider {

    use Utils;

    /** @var string the full token endpoint URL used to refresh the access token */
    private $token_url;
    /** @var string the OAuth client id */
    private $client_id;
    /** @var string the Options key the access token is stored under */
    private $access_token_option;
    /** @var string the Options key the refresh token is stored under */
    private $refresh_token_option;
    /** @var Client an unauthenticated client used only to make the token refresh request */
    private $client;

    /**
     * @param string $token_url the full token endpoint URL
     * @param string $client_id the OAuth client id
     * @param string $access_token_option the Options key for the access token
     * @param string $refresh_token_option the Options key for the refresh token
     */
    public function __construct(
        string $token_url,
        string $client_id,
        string $access_token_option,
        string $refresh_token_option
    ) {
        $this->token_url = $token_url;
        $this->client_id = $client_id;
        $this->access_token_option = $access_token_option;
        $this->refresh_token_option = $refresh_token_option;
        // An unauthenticated client so the refresh request itself is never re-authenticated/retried.
        $this->client = new Client();
    }

    /**
     * Build the provider for the Classic Leadpages OAuth backend.
     *
     * @return self
     */
    public static function for_classic(): self {
        $config = Config::get_instance();
        return new self(
            $config->get('ACCOUNT_API_URL') . 'oauth2/access-tokens',
            $config->get('OAUTH2_CLIENT_ID'),
            Options::$access_token,
            Options::$refresh_token
        );
    }

    /**
     * Build the provider for the Nova (new Leadpages) OAuth backend.
     *
     * @return self
     */
    public static function for_nova(): self {
        $config = Config::get_instance();
        return new self(
            $config->get('NOVA_APP_URL') . '/api/oauth/token',
            $config->get('NOVA_OAUTH_CLIENT_ID'),
            Options::$nova_access_token,
            Options::$nova_refresh_token
        );
    }

    /**
     * Attach the stored access token as a bearer Authorization header when one exists.
     *
     * @param array $options
     * @return array
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function applyAuth( array $options ): array {
        $access_token = Options::get($this->access_token_option);
        if (! empty($access_token)) {
            $options['headers']['Authorization'] = 'Bearer ' . $access_token;
        }
        return $options;
    }

    /**
     * Refresh the access token on an auth failure. On success the new access token (and the rotated
     * refresh token, when the server returns one) is stored and true is returned so the request is
     * retried. On failure both tokens are cleared (logging the user out of this backend) and false
     * is returned.
     *
     * @param array|\WP_Error $response
     * @return bool
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function handleAuthFailure( $response ): bool {
        $tokens = $this->refresh_access_token();
        if ($tokens && ! empty($tokens['access_token'])) {
            Options::set($this->access_token_option, $tokens['access_token']);
            // Public PKCE clients (Nova) rotate the refresh token on every refresh and revoke the
            // previous one. We MUST persist the new refresh token or the next refresh presents the
            // now-revoked token, fails, and silently logs the user out of this backend. When the
            // server does not rotate (e.g. Classic), no refresh_token is returned and we keep the
            // existing one.
            if (! empty($tokens['refresh_token'])) {
                Options::set($this->refresh_token_option, $tokens['refresh_token']);
            }
            return true;
        }

        Options::delete($this->refresh_token_option);
        Options::delete($this->access_token_option);
        return false;
    }

    /**
     * Refresh the access token using the stored refresh token. Returns the decoded token response
     * (with at least an access_token, plus a rotated refresh_token when the server issues one) on
     * success, or null otherwise.
     *
     * @param bool $retry whether to retry the refresh request on 500 errors
     * @return array|null
     */
    private function refresh_access_token( $retry = true ) {
        $refresh_token = Options::get($this->refresh_token_option);
        if (! $refresh_token) {
            return null;
        }

        $this->debug('Attempting to refresh access token');

        try {
            $response = $this->client->post(
                $this->token_url,
                [
                    'body'    => [
                        'refresh_token' => $refresh_token,
                        'client_id'     => $this->client_id,
                        'grant_type'    => 'refresh_token',
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                ]
            );
            $body = json_decode($response['body'], true);
            if (! is_array($body) || empty($body['access_token'])) {
                $this->debug('Refresh response missing access_token');
                return null;
            }

            $this->debug('Successfully refreshed access token');
            return $body;
        } catch (ServerException $e) {
            if ($retry) {
                $this->debug('Retrying access token refresh request');
                return $this->refresh_access_token(false);
            }
            $this->debug('Failed to refresh access token');
            return null;
        } catch (HttpException $e) {
            $this->debug('Failed to refresh access token');
            return null;
        }
    }
}
