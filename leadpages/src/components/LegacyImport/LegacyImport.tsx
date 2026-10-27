import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import NoticeManager from '../NoticeManager';
import { getErrorMessage, AUTH_ERROR_CODES } from '../../utils/api';
import {
    LegacyImportFailure,
    LegacyImportPage,
    LegacyImportResult,
    LegacyImportStatus,
    WPResponseError,
} from '../../types/api';
import './legacy_import.css';

const STATUS_LABELS: Record<LegacyImportStatus, string> = {
    imported: 'Live again',
    already_done: 'Already live',
    skipped: 'Not imported',
    not_found: 'Not found',
    failed: 'Failed',
};

const pageUrl = (page: LegacyImportPage) => {
    if (page.role === 'home') return 'Homepage';
    if (page.role === '404') return '404 page';
    return `/${page.path}`;
};

export interface Props {
    legacyPageCount: number;
    initialResult: LegacyImportResult | null;
    initialFailure: LegacyImportFailure | null;
    onDone: () => void;
    onAuthenticationError: () => void;
}

const LegacyImport: React.FC<Props> = ({
    legacyPageCount,
    initialResult,
    initialFailure,
    onDone,
    onAuthenticationError,
}) => {
    // Signing in to the new Leadpages is the go-ahead: the pages are down until this runs, and the import
    // only fills old URLs nothing on the site serves yet. It starts once, on the first visit with no
    // stored result; after that, or after an attempt that did not finish, it waits for a click.
    const autoStart = !initialResult && !initialFailure;
    // Start on the loading screen when the import starts on mount, so the retry screen never flashes first.
    const [importing, setImporting] = useState(autoStart);
    const [result, setResult] = useState<LegacyImportResult | null>(initialResult);
    const [error, setError] = useState<string | null>(initialFailure ? initialFailure.message : null);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const autoStarted = useRef(false);
    let view = 'intro';
    if (importing) {
        view = 'importing';
    } else if (result) {
        view = 'result';
    }

    // The clicked button disappears with each change of view, so move focus to the new heading. That
    // also makes screen readers announce the loading and result screens.
    useEffect(() => {
        if (view !== 'intro') {
            headingRef.current?.focus();
        }
    }, [view]);

    useEffect(() => {
        if (autoStart && !autoStarted.current) {
            autoStarted.current = true;
            handleImport();
        }
        // Mount only: re-running on a new autoStart or handleImport would start a second import.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleImport = async () => {
        setImporting(true);
        setError(null);
        try {
            setResult((await apiFetch({ path: '/leadpages/v1/legacy-import', method: 'POST' })) as LegacyImportResult);
        } catch (e) {
            const err = e as WPResponseError;
            if (AUTH_ERROR_CODES.indexOf(err.code) !== -1) {
                onAuthenticationError();
                return;
            }
            setError(getErrorMessage(err));
        } finally {
            setImporting(false);
        }
    };

    if (importing) {
        return (
            <div className="root legacy-import" role="status" aria-live="polite">
                <h1 className="heading" ref={headingRef} tabIndex={-1}>
                    Republishing your pages…
                </h1>
                <div className="legacy-import-progress">
                    <Spinner />
                    <p>
                        We’re matching the {legacyPageCount === 1 ? 'page' : 'pages'} from the old Leadpages Connector
                        to your Leadpages account and putting each one back at its old URL. On a site with many pages
                        this can take a few minutes. Keep this tab open.
                    </p>
                </div>
            </div>
        );
    }

    if (result) {
        const live = result.pages.filter((page) => page.status === 'imported' || page.status === 'already_done');
        const nothingMatched = result.pages.length > 0 && result.pages.every((page) => page.status === 'not_found');
        return (
            <div className="root legacy-import">
                <h1 className="heading" ref={headingRef} tabIndex={-1}>
                    Your pages from the old plugin
                </h1>
                <p className="legacy-import-summary">
                    {live.length} of {result.pages.length} {result.pages.length === 1 ? 'page is' : 'pages are'} live at{' '}
                    {result.pages.length === 1 ? 'its' : 'their'} old URL.
                </p>
                {nothingMatched && (
                    <NoticeManager
                        status="error"
                        isDismissible={false}
                        messages={[
                            'None of these pages are in the Leadpages account you connected. If you have another Leadpages account, sign out and connect that one instead.',
                        ]}
                    />
                )}
                {error && <NoticeManager status="error" isDismissible={false} messages={[error]} />}
                <table className="legacy-import-table">
                    <thead>
                        <tr>
                            <th>Old URL</th>
                            <th>Leadpages page</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        {result.pages.map((page, index) => (
                            <tr key={index}>
                                <td>{pageUrl(page)}</td>
                                <td>{page.title ?? '—'}</td>
                                <td>
                                    <span className={`legacy-import-status legacy-import-status--${page.status}`}>
                                        {STATUS_LABELS[page.status]}
                                    </span>
                                    {page.message && <div className="legacy-import-message">{page.message}</div>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="legacy-import-actions">
                    <Button variant="primary" onClick={onDone}>
                        Continue to your pages
                    </Button>
                    <Button variant="secondary" onClick={handleImport}>
                        Run the import again
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="root legacy-import">
            <h1 className="heading">We couldn’t republish your pages yet</h1>
            <p>
                {legacyPageCount === 1
                    ? 'This site has 1 page from the old Leadpages Connector plugin that isn’t live yet. Try again to republish it at its old URL using the matching page in your Leadpages account.'
                    : `This site has ${legacyPageCount} pages from the old Leadpages Connector plugin that aren’t live yet. Try again to republish them at their old URLs using the matching pages in your Leadpages account.`}
            </p>
            <p>Nothing on your site is deleted. Pages we can’t match are listed so you can publish them yourself.</p>
            {error && <NoticeManager status="error" isDismissible={false} messages={[error]} />}
            <div className="legacy-import-actions">
                <Button variant="primary" onClick={handleImport}>
                    Try again
                </Button>
                <Button variant="tertiary" onClick={onDone}>
                    Not now
                </Button>
            </div>
        </div>
    );
};

export default LegacyImport;
