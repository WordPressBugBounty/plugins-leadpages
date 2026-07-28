<?php

namespace Leadpages\providers\http\auth;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

/**
 * A strategy for authenticating outbound requests made through the http Client.
 *
 * The Client is backend-agnostic: it delegates "how do I authenticate this request" and "what do I
 * do when the server rejects my credentials" to an AuthProvider. This lets a single Client
 * implementation serve both the Classic and Nova (new Leadpages) OAuth backends.
 */
interface AuthProvider {

    /**
     * Attach authentication to a set of wp_remote_request options and return the modified options.
     *
     * @param array $options the request options passed to the Client
     * @return array the options with authentication applied (e.g. an Authorization header)
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function applyAuth( array $options ): array;

    /**
     * Handle a request that failed authentication (a 401 or 403 response).
     *
     * Implementations decide whether to refresh the credentials (returning true so the Client
     * retries the request once) or to clear the now-invalid credentials (returning false so the
     * Client surfaces the auth failure).
     *
     * @param array|\WP_Error $response the failed response from wp_remote_request
     * @return bool true if the request should be retried, false otherwise
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function handleAuthFailure( $response ): bool;
}
