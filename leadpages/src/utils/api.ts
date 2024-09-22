import type { WPResponseError } from '../types/api';

export const AUTH_ERROR_CODES = ['leadpages_auth_error', 'no_token'];

export const getErrorMessage = (e: WPResponseError): string => {
    const code = e.code;
    let message = e.message;

    if (code === 'rest_invalid_param' && e.data?.params?.slug) {
        // simple request where the only thing open to user error is the slug param
        message = e.data.params.slug;
    }
    // add more special handling of error codes here

    return `${message} - ${code}`;
};
