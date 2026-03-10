<?php
/**
 * Login Complete Page Template
 *
 * This page has one job: to communicate from the popup window to the original window the final state
 * of the OAuth2 login. This has to be done from a page hosted by WordPress so that the page origins are
 * the same. Using the BroadcastChannel API, send the success status or the error message to the primary window
 * and immediately close the popup.
 *
 * We are opting not to do this with React because it is faster to send a script tag with this logic
 * than too enqueue a pre-built script with a dependency on React.
 */
?>

<div id="fallback-root"></div>

<script type="text/javascript">
    var OAUTH_CHANNEL = 'oauth_channel';
    var LP_ERROR_PARAM = 'lperror';
    var ACCESS_DENIED = 'access_denied';
    var INVALID_CODE = 'invalid_code';

    /**
     * When OAuth2 login fails, either because the user denied access or because of an
     * issue with the authorization server, an error code will be present as a URL search
     * parameter. Use this code to determine which error message to return.
     */
    function getErrorMessageFromParam() {
        var urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.has(LP_ERROR_PARAM)) {
            return '';
        }

        var errorCode = urlParams.get(LP_ERROR_PARAM);
        if (errorCode === ACCESS_DENIED) {
            return 'Access was denied.';
        } else if (errorCode === INVALID_CODE) {
            return 'We encountered an error while processing your request. Please try again.';
        }
        return 'An unknown error occurred.';
    };

    (function LoginCompleteView() {
        var errorMessage = getErrorMessageFromParam();

        if (window.BroadcastChannel) {
            var oauthChannel = new BroadcastChannel(OAUTH_CHANNEL);

            // post the result back to the opening window
            if (!errorMessage) {
                oauthChannel.postMessage({ success: true, error: '' });
            } else {
                oauthChannel.postMessage({ success: false, error: errorMessage});
            }

            oauthChannel.close();
            window.close();
        } else {
            // fallback to showing a simple message to the user.
            console.error('BroadcastChannel not supported by your browser.');
            document.getElementById('fallback-root')
                .innerHTML = errorMessage ? errorMessage : 'Login successful. You may close this window.';
        }
    })();
</script>
