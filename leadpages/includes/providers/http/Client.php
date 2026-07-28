<?php

namespace Leadpages\providers\http;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use InvalidArgumentException;
use Leadpages\providers\http\exceptions\RequestFailureException;
use Leadpages\providers\http\exceptions\ServerException;
use Leadpages\providers\http\exceptions\ClientException;
use Leadpages\providers\http\exceptions\NotFoundException;
use Leadpages\providers\http\exceptions\AuthException;
use Leadpages\providers\http\auth\AuthProvider;
use Leadpages\providers\Utils;
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

    /** @var AuthProvider|null strategy used to authenticate requests and recover from auth failures */
    private $auth_provider;

    /**
     * HTTP methods supported by this client
     * @var string[]
     */
    private static $methods = [ 'delete', 'head', 'get', 'post', 'put' ];

    /** native ssl cert - only used in local development */
    private $ssl_cert = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

    /**
     * @param AuthProvider|null $auth_provider when provided, the client authenticates every request
     * with it and delegates 401/403 recovery to it. When null, the client makes unauthenticated
     * requests and treats a 401/403 as an unrecoverable AuthException.
     */
    public function __construct( ?AuthProvider $auth_provider = null ) {
        $this->auth_provider = $auth_provider;
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

        // Authenticate the request through the active provider (if any) before it is sent.
        if (null !== $this->auth_provider) {
            $options = $this->auth_provider->applyAuth($options);
        }

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

        // On an auth failure, let the provider recover (e.g. refresh the access token). If it can,
        // retry the request once with the refreshed credentials re-applied; otherwise the provider
        // has cleared the invalid credentials and we surface the failure. Without a provider the
        // request is unauthenticated and a 401/403 is always unrecoverable.
        if (401 === $status_code || 403 === $status_code) {
            if (null !== $this->auth_provider && $refresh && $this->auth_provider->handleAuthFailure($response)) {
                return $this->request($method, $url, $options, false);
            }
            throw new AuthException($response);
        }

        if (400 <= $status_code) {
            throw new ClientException($response);
        }

        return $response;
        //phpcs:enable
    }
}
