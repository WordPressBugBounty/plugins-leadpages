import type { Direction, OrderBy, Status } from './table';

export interface MetaData {
    total: number;
    page: number;
    perPage: number;
}

export interface LandingPage {
    id: number;
    name: string;
    visitors: number;
    conversion_rate: number;
    last_published: string;
    published_url: string;
    uuid: string;
    wp_slug: string;
    lp_slug: string;
    kind: 'LeadpageV3' | 'LeadpageSplitTestV2';
}

export interface LandingPageResponse {
    data: LandingPage[];
    meta: MetaData;
}

export interface QueryState {
    orderBy: OrderBy;
    direction: Direction;
    status: Status;
    page: number;
    perPage: number;
    search: string;
}

export interface LoginStatusResponse {
    isLoggedIn: boolean;
}

/**
 * This is not an exhaustive list of possible error codes that could be returned from the server
 * or the perfect typing of the error response format. Be smart. Inspect the response object
 * and use this just to make TypeScript happy
 */

export type ErrorCodes = 'name_conflict' | 'lp_sync_error' | 'lp_error' | 'lp_wp_error' | 'rest_invalid_param';

export interface WPResponseError {
    code: ErrorCodes;
    message: string;
    data: {
        status: number;
        params: {
            [key: string]: string;
        };
        details: {
            [key: string]: {
                code: ErrorCodes;
                message: string;
                data: {
                    status: number;
                };
            };
        };
    };
}
