import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import NoticeManager from '../NoticeManager';
import Pagination from '../Pagination';
import { getErrorMessage, AUTH_ERROR_CODES } from '../../utils/api';
import {
    LegacyImportFailure,
    LegacyImportPage,
    LegacyImportResult,
    LegacyImportStatus,
    LegacyImportStatusResponse,
    LegacyPendingPage,
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

const pageUrl = (page: Pick<LegacyImportPage, 'path' | 'role'>) => {
    if (page.role === 'home') return 'Homepage';
    if (page.role === '404') return '404 page';
    return `/${page.path}`;
};

const pageCount = (count: number) => `${count} ${count === 1 ? 'page' : 'pages'}`;

export interface Props {
    pages: LegacyPendingPage[];
    initialResult: LegacyImportResult | null;
    initialFailure: LegacyImportFailure | null;
    onDone: () => void;
    onAuthenticationError: () => void;
}

const LegacyImport: React.FC<Props> = ({ pages, initialResult, initialFailure, onDone, onAuthenticationError }) => {
    const [pending, setPending] = useState(pages);
    // The old plugin's records can name far more pages than it had live, so nothing is republished until
    // the owner picks the pages. The first review starts with every page checked; a later one starts empty,
    // so the pages left off last time are not put forward again.
    const [selected, setSelected] = useState<Set<number>>(
        () => new Set(initialResult ? [] : pages.map((page) => page.id))
    );
    const [reviewing, setReviewing] = useState(!initialResult);
    const [importing, setImporting] = useState(false);
    const [result, setResult] = useState<LegacyImportResult | null>(initialResult);
    const [error, setError] = useState<string | null>(initialFailure ? initialFailure.message : null);
    const [listPage, setListPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const headingRef = useRef<HTMLHeadingElement>(null);
    let view = 'result';
    if (importing) {
        view = 'importing';
    } else if (reviewing || !result) {
        view = 'review';
    }

    // The clicked button disappears with each change of view, so move focus to the new heading. That
    // also makes screen readers announce the loading and result screens.
    const firstView = useRef(true);
    useEffect(() => {
        if (firstView.current) {
            firstView.current = false;
            return;
        }
        headingRef.current?.focus();
    }, [view]);

    const handleImport = async () => {
        setImporting(true);
        setError(null);
        try {
            const importResult = (await apiFetch({
                path: '/leadpages/v1/legacy-import',
                method: 'POST',
                data: { postIds: Array.from(selected) },
            })) as LegacyImportResult;
            setResult(importResult);
            setReviewing(false);
            setSelected(new Set());
            setListPage(1);
            // Pages now live drop off the list; the ones left off stay for a later review.
            const status = (await apiFetch({ path: '/leadpages/v1/legacy-import' }).catch(
                () => null
            )) as LegacyImportStatusResponse | null;
            if (status) {
                setPending(status.pages);
            }
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

    const handleToggle = (id: number) => {
        const next = new Set(selected);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        setSelected(next);
    };

    const handleTogglePage = (rows: LegacyPendingPage[], checked: boolean) => {
        const next = new Set(selected);
        rows.forEach((row) => (checked ? next.add(row.id) : next.delete(row.id)));
        setSelected(next);
    };

    const handleListPage = (page: number, size: number) => {
        setListPage(page);
        setPerPage(size);
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
                        We’re matching the {selected.size === 1 ? 'page' : 'pages'} you picked to your Leadpages account
                        and putting each one back at its old URL. On a site with many pages this can take a few minutes.
                        Keep this tab open.
                    </p>
                </div>
            </div>
        );
    }

    if (view === 'result' && result) {
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
                    {pending.length > 0 &&
                        ` ${pageCount(pending.length)} from the old plugin ${pending.length === 1 ? 'is' : 'are'} still not live.`}
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
                    {pending.length > 0 && (
                        <Button variant="secondary" onClick={() => setReviewing(true)}>
                            Choose more pages to republish
                        </Button>
                    )}
                </div>
            </div>
        );
    }

    const lastPage = Math.max(1, Math.ceil(pending.length / perPage));
    const currentPage = Math.min(listPage, lastPage);
    const rows = pending.slice((currentPage - 1) * perPage, currentPage * perPage);
    const allRowsChecked = rows.length > 0 && rows.every((row) => selected.has(row.id));

    return (
        <div className="root legacy-import">
            <h1 className="heading" ref={headingRef} tabIndex={-1}>
                Choose the pages to republish
            </h1>
            <p>
                {pending.length === 1
                    ? 'This page was published with the old Leadpages Connector plugin and isn’t live on this site yet.'
                    : `These ${pending.length} pages were published with the old Leadpages Connector plugin and aren’t live on this site yet.`}{' '}
                Each page you check goes back live at its old URL, using the matching page in your Leadpages account.
            </p>
            <p>
                Pages you leave unchecked stay off. You can come back to them any time from Old Connector pages. Nothing
                on your site is deleted.
            </p>
            {error && <NoticeManager status="error" isDismissible={false} messages={[error]} />}
            <div className="legacy-import-selection">
                <span>
                    {selected.size} of {pageCount(pending.length)} selected
                </span>
                <Button
                    variant="link"
                    disabled={selected.size === pending.length}
                    onClick={() => setSelected(new Set(pending.map((page) => page.id)))}
                >
                    Select all {pending.length}
                </Button>
                <Button variant="link" disabled={selected.size === 0} onClick={() => setSelected(new Set())}>
                    Clear selection
                </Button>
            </div>
            <table className="legacy-import-table">
                <thead>
                    <tr>
                        <th className="legacy-import-check">
                            <input
                                type="checkbox"
                                aria-label="Select every page on this screen"
                                checked={allRowsChecked}
                                onChange={(event) => handleTogglePage(rows, event.target.checked)}
                            />
                        </th>
                        <th>Page in the old plugin</th>
                        <th>Old URL</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((page) => (
                        <tr key={page.id}>
                            <td className="legacy-import-check">
                                <input
                                    type="checkbox"
                                    id={`legacy-import-page-${page.id}`}
                                    checked={selected.has(page.id)}
                                    onChange={() => handleToggle(page.id)}
                                />
                            </td>
                            <td>
                                <label htmlFor={`legacy-import-page-${page.id}`}>{page.title || '(no title)'}</label>
                            </td>
                            <td>{page.urls.map(pageUrl).join(', ')}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {pending.length > 20 && (
                <Pagination metaData={{ total: pending.length, page: currentPage, perPage }} onPage={handleListPage} />
            )}
            <div className="legacy-import-actions">
                <Button variant="primary" disabled={selected.size === 0} onClick={handleImport}>
                    {selected.size === 0 ? 'Republish selected pages' : `Republish ${pageCount(selected.size)}`}
                </Button>
                <Button variant="tertiary" onClick={result ? () => setReviewing(false) : onDone}>
                    Not now
                </Button>
            </div>
        </div>
    );
};

export default LegacyImport;
