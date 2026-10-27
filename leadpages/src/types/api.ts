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
    // 'home' when the page is also served at the site's homepage URL, '404' when it is also served
    // in place of the theme's "not found" page.
    wp_page_type?: string | null;
    lp_slug: string;
    kind: 'LeadpageV3' | 'LeadpageSplitTestV2' | 'NovaPage';
    // Present on rows synced from the new Leadpages (Nova) platform.
    platform?: 'classic' | 'nova';
    nova_page_id?: string;
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

export interface NovaStatusResponse {
    isConnected: boolean;
    platform: string;
}

/**
 * This is not an exhaustive list of possible error codes that could be returned from the server
 * or the perfect typing of the error response format. Be smart. Inspect the response object
 * and use this just to make TypeScript happy
 */

export type ErrorCodes =
    | 'name_conflict'
    | 'slug_taken_by_page'
    | 'lp_sync_error'
    | 'lp_error'
    | 'lp_wp_error'
    | 'rest_invalid_param';

export interface WPResponseError {
    code: ErrorCodes;
    message: string;
    data: {
        status: number;
        // slug_taken_by_page: the WordPress page type ('home' or '404') of the page holding the slug
        conflictingPageType?: 'home' | '404' | null;
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

export type LegacyImportStatus = 'imported' | 'already_done' | 'skipped' | 'not_found' | 'failed';

export interface LegacyImportPage {
    path: string;
    role: 'home' | '404' | null;
    title: string | null;
    status: LegacyImportStatus;
    message: string;
}

export interface LegacyImportResult {
    ranAt: string;
    pages: LegacyImportPage[];
}

// The last import that did not finish, until one does.
export interface LegacyImportFailure {
    attemptedAt: string;
    message: string;
}

export interface LegacyImportStatusResponse {
    legacyPageCount: number;
    result: LegacyImportResult | null;
    failure: LegacyImportFailure | null;
}
