<?php

namespace Leadpages\providers\http;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use InvalidArgumentException;
use Leadpages\models\Options;
use Leadpages\providers\http\exceptions\RequestFailureException;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\http\exceptions\ClientException;
use Leadpages\providers\http\exceptions\NotFoundException;
use Leadpages\providers\http\exceptions\AuthException;
use Leadpages\providers\http\exceptions\HttpException;
use Leadpages\providers\Utils;
use Leadpages\providers\config\Config;
use WP_Error;

/**
 * A Client class for making HTTP requests.
 *
 * Usage:
 *   $client = new Client();
 *   $response = $client->get( url, options );
 *
 *   where options is an array of options to pass to wp_remote_request with an optional 'query' key
 *
 * Example:
 *   // make a get request to http://example.com?foo=bar with the header 'Accept: application/json'
 *   $client = new Client();
 *   $response = $client->get( 'http://example.com', [
 *       'query' => [ 'foo' => 'bar' ],
 *       'headers' => [ 'Accept' => 'application/json' ]
 *     ]
 *   );
 *
 */
class Client {

    use Utils;

    /** @var Config */
    private $config;

    /**
     * HTTP methods supported by this client
     * @var string[]
     */
    private static $methods = [ 'delete', 'head', 'get', 'post', 'put' ];

    /** native ssl cert - only used in local development */
    private $ssl_cert = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

    public function __construct() {
        $this->config = Config::get_instance();
    }

    /**
     * Make a request
     *
     * @param string $name
     * @param array $args - options to pass to wp_remote_request
     * @return WP_HTTP_Response
     * @throws RequestFailureException|ServerException|ClientException|NotFoundException|InvalidArgumentException
     */
    public function __call( $name, $args ) {
        if (! in_array($name, static::$methods, true)) {
            throw new \InvalidArgumentException(esc_html("Unknown function or method $name"));
        }
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Request method missing required URL argument');
        }

        $method = $name;
        $uri = $args[0];
        $opts = isset($args[1]) ? $args[1] : [];

        return $this->request($method, $uri, $opts);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     * @return array|WP_Error
     * @throws InvalidArgumentException
     */
    private function call( $method, $url, $options = [] ) {
        $options['method'] = strtoupper($method);
        $query_string = '';
        $query_args = isset($options['query']) ? $options['query'] : [];

        // use the WordPress provided certificate when in our local environment
        if (wp_get_environment_type() === 'local') {
            $options['sslcertificates'] = $this->ssl_cert;
        }
        // Leadpages test environment uses fake ssl certificates for custom domains
        if (wp_get_environment_type() === 'development') {
            $options['sslverify'] = false;
        }

        // build the query string
        if (isset($options['query'])) {
            $query_args = $options['query'];
            // query is not part of the WP HTTP API, so strip it here.
            unset($options['query']);

            if (! is_array($query_args)) {
                throw new \InvalidArgumentException('Query parameters must be an array');
            }

            $query_string = http_build_query($query_args);
            $query_string = "?{$query_string}";
        }

        $uri = "{$url}{$query_string}";
        $this->debug('Making request to ' . $uri);
        return wp_remote_request($uri, $options);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     * @param bool $refresh whether to attempt to refresh the access token on 401 or 403 responses
     * to requests that require authentication
     */
    private function request( $method, $url, $options = [], $refresh = true ) {
        // Nothing to escape here
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        $response = $this->call($method, $url, $options);
        if (is_wp_error($response)) {
            throw new RequestFailureException($response);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if (500 <= $status_code) {
            throw new ServerException($response);
        }
        if (404 === $status_code) {
            throw new NotFoundException($response);
        }

        // Attempt to auto refresh the access token and retry the request if we get a 401
        // or 403 response to a request with an Authorization header. If this fails,
        // we delete the refresh and access tokens from the options table and throw an exception
        // so the plugin knows the user is no longer logged in.
        if (401 === $status_code || 403 === $status_code) {
            $is_authed_request = isset($options['headers']['Authorization']);
            if ($is_authed_request) {
                if ($refresh) {
                    $token = $this->refresh_access_token();
                    if ($token) {
                        Options::set(Options::$access_token, $token);
                        $options['headers']['Authorization'] = "Bearer $token";
                        return $this->request($method, $url, $options, false);
                    }
                }
                Options::delete(Options::$refresh_token);
                Options::delete(Options::$access_token);
                throw new AuthException($response);
            } else {
                throw new AuthException($response);
            }
        }

        if (400 <= $status_code) {
            throw new ClientException($response);
        }

        return $response;
        //phpcs:enable
    }

    /**
     * Refreshes the access token using the refresh token saved in the options table.
     * If this is successful, the new access token will be returned. Otherwise returns null.
     *
     * @param bool $retry whether to retry the refresh request on 500 errors
     * @return string|null
     */
    private function refresh_access_token( $retry = true ) {
        $refresh_token = Options::get(Options::$refresh_token);
        if (! $refresh_token) {
            return null;
        }

        $this->debug("Attempting to refresh access token with refresh token: $refresh_token");

        try {
            $response = $this->post(
                $this->config->get('ACCOUNT_API_URL') . 'oauth2/access-tokens',
                [
                    'body'    => [
                        'refresh_token' => $refresh_token,
                        'client_id'     => $this->config->get('OAUTH2_CLIENT_ID'),
                        'grant_type'    => 'refresh_token',
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                ]
            );
            $body = json_decode($response['body'], true);

            $this->debug('Successfully refreshed access token');
            return $body['access_token'];
        } catch (ServerException $e) {
            if ($retry) {
                $this->debug('Retrying access token refresh request');
                return $this->refresh_access_token(false);
            }
            $this->debug('Failed to refresh access token');
            return null;
        } catch (HTTPException $e) {
            $this->debug('Failed to refresh access token');
            return null;
        }
    }
}
