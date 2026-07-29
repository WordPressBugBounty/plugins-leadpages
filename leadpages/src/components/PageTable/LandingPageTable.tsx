import { useState } from '@wordpress/element';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { escapeHTML } from '@wordpress/escape-html';
import Caret from '../Caret';
import Pagination from '../Pagination';
import { handleSortChange } from '../../utils/sorting';
import { BUILDER_URL, LEADPAGES_URL, HOME_URL, NOVA_DASHBOARD_URL, NOVA_APP_URL } from '../../utils/config';
import { formatDate, formatNumber, formatPercentage } from '../../utils/formatting';
import { OrderBy, Direction } from '../../types/table';
import { MetaData, LandingPage } from '../../types/api';
import './landing_page_table.css';

export const columnDisplayNames: Partial<Record<keyof LandingPage, string>> = {
    name: 'Page name',
    visitors: 'Unique visitors',
    conversion_rate: 'Conversion rate',
    last_published: 'Last modified',
    wp_slug: 'Slug',
};

export interface Props {
    columns: Array<keyof LandingPage>;
    pages: LandingPage[];
    actions: string[];
    pagination: MetaData;
    onPagination: (page: number, perPage: number) => void;
    onSortChange: (orderBy: OrderBy, direction: Direction) => void;
    onAction: (action: string, page: LandingPage) => void;
    sortData: { orderBy: OrderBy; direction: Direction };
}

export const actionItemLabel = {
    EDIT_PAGE: 'edit',
    EDIT_IN_LEADPAGES: 'edit-leadpages',
    UNPUBLISH: 'unpublish',
    PUBLISH_TO_WORDPRESS: 'publish',
    VIEW_LEADPAGES_URL: 'view-lp',
    VIEW_WORDPRESS_URL: 'view-wp',
};

const isSortableColumn = (column: keyof LandingPage): boolean => {
    return ['name', 'last_published'].includes(column);
};

const isNovaPage = (page: LandingPage): boolean => page.platform === 'nova' || page.kind === 'NovaPage';

// Platform badge for the tile: a landing-page glyph for both, with an AI sparkle on the new
// Leadpages (Nova) to distinguish it from Classic (matches the connect screen's AI motif).
const PlatformIcon: React.FC<{ nova: boolean }> = ({ nova }) =>
    nova ? (
        <svg viewBox="0 0 28 28" fill="none" aria-hidden="true">
            <rect x="4" y="6" width="16" height="18" rx="3" stroke="currentColor" strokeWidth="2" />
            <rect x="7" y="9" width="10" height="4" rx="1.5" fill="currentColor" />
            <path d="M7 16.5h10M7 20h6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            <path d="M22.5 3l.85 2.15L25.5 6l-2.15.85L22.5 9l-.85-2.15L19.5 6l2.15-.85L22.5 3z" fill="currentColor" />
        </svg>
    ) : (
        <svg viewBox="0 0 28 28" fill="none" aria-hidden="true">
            <rect x="5" y="4" width="18" height="20" rx="3" stroke="currentColor" strokeWidth="2" />
            <rect x="8.5" y="7.5" width="11" height="4.5" rx="1.5" fill="currentColor" />
            <path d="M8.5 15.5h11M8.5 19.5h7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );

const getActionItem = (
    action: string,
    page: LandingPage,
    onAction: (action: string, page: LandingPage) => void
): { label: string; url: string; type: string; className: string; handler?: () => void } => {
    let label = '';
    let url = '#';
    let type: 'link' | 'function' = 'link';
    let className = '';
    let handler = () => {};
    const isSplitTest = page.kind === 'LeadpageSplitTestV2';
    // Nova pages are edited in the new Leadpages editor at /edit/{pageId}, not the Classic builder.
    const editLPBuilderUrl =
        page.platform === 'nova'
            ? `${NOVA_DASHBOARD_URL}/edit/${page.nova_page_id || page.uuid}`
            : isSplitTest
              ? `${LEADPAGES_URL}#/split-test-analytics/${page.uuid}`
              : `${BUILDER_URL}#/edit/${page.uuid}`;

    switch (action) {
        case actionItemLabel.EDIT_PAGE:
            label = 'Edit';
            type = 'function';
            className = 'edit-link';
            handler = () => onAction(actionItemLabel.EDIT_PAGE, page);
            break;
        case actionItemLabel.EDIT_IN_LEADPAGES:
            label = 'Edit in Leadpages';
            url = editLPBuilderUrl;
            className = 'action-link';
            type = 'link';
            break;
        case actionItemLabel.UNPUBLISH:
            label = 'Unpublish';
            type = 'function';
            className = 'unpublish-link';
            handler = () => onAction(actionItemLabel.UNPUBLISH, page);
            break;
        case actionItemLabel.PUBLISH_TO_WORDPRESS:
            label = 'Publish to WordPress';
            type = 'function';
            className = 'publish-link';
            handler = () => onAction(actionItemLabel.PUBLISH_TO_WORDPRESS, page);
            break;
        case actionItemLabel.VIEW_LEADPAGES_URL:
            label = 'View';
            url = page.published_url;
            type = 'link';
            className = 'action-link';
            break;
        case actionItemLabel.VIEW_WORDPRESS_URL:
            label = 'View';
            url = HOME_URL + '/' + page.wp_slug;
            type = 'link';
            className = 'action-link';
            break;
        default:
            break;
    }

    return { label, url, className, type, handler };
};

// escapeHTML() throws on null/undefined (it calls value.replace). Nova rows can
// have null text fields (e.g. an untitled page), so coerce to a string first.
const safeText = (value: unknown): string => escapeHTML(value == null ? '' : String(value));

const analyticsUrl = (metric: 'unique_views' | 'conversion_rate', page: LandingPage): string => {
    const isSplitTest = page.kind === 'LeadpageSplitTestV2';
    // Nova pages link to the new Leadpages dashboard for analytics.
    const base =
        page.platform === 'nova'
            ? NOVA_DASHBOARD_URL
            : isSplitTest
              ? `${LEADPAGES_URL}#/split-test-analytics/${page.uuid}`
              : `${LEADPAGES_URL}#/pages/${page.uuid}/analytics/`;
    return `${base}?metric=${metric}`;
};

const PageCard: React.FC<{
    page: LandingPage;
    columns: Array<keyof LandingPage>;
    actions: string[];
    onAction: (action: string, page: LandingPage) => void;
}> = ({ page, columns, actions, onAction }) => {
    const nova = isNovaPage(page);
    const [thumbFailed, setThumbFailed] = useState(false);
    // New-Leadpages (Nova) pages have a real page thumbnail served from the public, cached
    // /api/thumbnails/{id} endpoint (the same source the Leadpages dashboard uses). It returns 404
    // when a page has no thumbnail, so the onError handler falls back to the platform icon. Classic
    // pages live in a different backend with no such image and always use the icon.
    const thumbUrl =
        nova && page.nova_page_id
            ? `${NOVA_APP_URL}/api/thumbnails/${encodeURIComponent(page.nova_page_id)}`
            : null;
    const live = Boolean(page.wp_slug);
    const showSlug = columns.includes('wp_slug') && live;
    const showVisitors = columns.includes('visitors');
    const showConversion = columns.includes('conversion_rate');
    const showModified = columns.includes('last_published');

    return (
        <div className="lp-card">
            <div className={`lp-tile ${nova ? 'lp-tile-nova' : 'lp-tile-classic'}`} aria-hidden="true">
                {thumbUrl && !thumbFailed ? (
                    <img
                        className="lp-tile-img"
                        src={thumbUrl}
                        alt=""
                        loading="lazy"
                        onError={() => setThumbFailed(true)}
                    />
                ) : (
                    <PlatformIcon nova={nova} />
                )}
            </div>

            <div className="lp-card-meta">
                <div className="lp-card-name">{safeText(page.name)}</div>

                <div className="lp-card-subrow">
                    {showSlug && (
                        <a
                            className="lp-card-url"
                            href={HOME_URL + '/' + page.wp_slug}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                <path d="M4 12h16M14 6l6 6-6 6" />
                            </svg>
                            /{safeText(page.wp_slug)}
                        </a>
                    )}

                    <div className="lp-card-pills">
                        <span className={`lp-pill ${live ? 'lp-pill-live' : 'lp-pill-available'}`}>
                            <span className="lp-led" />
                            {live ? 'Live' : 'Available'}
                        </span>
                        <span className={`lp-pill ${nova ? 'lp-pill-nova' : 'lp-pill-classic'}`}>
                            {nova ? 'New Leadpages' : 'Classic'}
                        </span>
                    </div>

                    {showModified && page.last_published && (
                        <span className="lp-card-modified">Last modified {formatDate(page.last_published)}</span>
                    )}
                </div>
            </div>

            {(showVisitors || showConversion) && (
                <div className="lp-card-stats">
                    {showVisitors && (
                        <a
                            className="lp-stat"
                            href={analyticsUrl('unique_views', page)}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <span className="lp-stat-n">{formatNumber(page.visitors)}</span>
                            <span className="lp-stat-l">Unique visitors</span>
                        </a>
                    )}
                    {showConversion && (
                        <a
                            className="lp-stat"
                            href={analyticsUrl('conversion_rate', page)}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <span className="lp-stat-n">{formatPercentage(page.conversion_rate)}</span>
                            <span className="lp-stat-l">Conversion rate</span>
                        </a>
                    )}
                </div>
            )}

            <div className="lp-card-actions">
                <DropdownMenu
                    className="lp-card-menu"
                    icon={moreVertical}
                    label="Page actions"
                    popoverProps={{ className: 'lp-card-menu-popover', placement: 'bottom-end' }}
                >
                    {({ onClose }) => (
                        <MenuGroup>
                            {actions.map((action) => {
                                const { label, url, type, handler, className } = getActionItem(
                                    action,
                                    page,
                                    onAction
                                );
                                return (
                                    <MenuItem
                                        key={action}
                                        className={className}
                                        onClick={() => {
                                            if (type === 'link') {
                                                // Guard against an empty URL (e.g. a View action on a
                                                // page with no published_url), which would otherwise
                                                // open a blank about:blank tab.
                                                if (url) {
                                                    window.open(url, '_blank', 'noopener,noreferrer');
                                                }
                                            } else {
                                                handler?.();
                                            }
                                            onClose();
                                        }}
                                    >
                                        {label}
                                    </MenuItem>
                                );
                            })}
                        </MenuGroup>
                    )}
                </DropdownMenu>
            </div>
        </div>
    );
};

const LandingPageTable: React.FC<Props> = ({
    pagination,
    columns,
    pages,
    actions,
    onSortChange,
    onPagination,
    onAction,
    sortData,
}: Props) => {
    const [showCaret, setShowCaret] = useState(false);

    const handleColumnSort = (column: keyof LandingPage) => {
        const direction = sortData.orderBy === column && sortData.direction === 'desc' ? 'asc' : 'desc';
        handleSortChange(column, direction, onSortChange, setShowCaret);
    };

    const sortableColumns = columns.filter(isSortableColumn);

    return (
        <div className="lp-pagelist">
            {sortableColumns.length > 0 && (
                <div className="lp-sortbar">
                    <span className="lp-sortbar-label">Sort by</span>
                    {sortableColumns.map((column) => {
                        const isActive = sortData.orderBy === column;
                        const directionText = sortData.direction === 'asc' ? 'ascending' : 'descending';
                        return (
                            <button
                                key={column}
                                className={`lp-sort-btn ${isActive ? 'is-active' : ''}`}
                                onClick={() => handleColumnSort(column)}
                                aria-pressed={isActive}
                                aria-label={
                                    isActive
                                        ? `Sort by ${columnDisplayNames[column]}, ${directionText}`
                                        : `Sort by ${columnDisplayNames[column]}`
                                }
                            >
                                {columnDisplayNames[column]}
                                {showCaret && isActive && <Caret up={sortData.direction === 'asc'} />}
                            </button>
                        );
                    })}
                </div>
            )}

            <div className="lp-cards">
                {pages.map((page, index) => (
                    <PageCard
                        key={page.uuid || index}
                        page={page}
                        columns={columns}
                        actions={actions}
                        onAction={onAction}
                    />
                ))}
            </div>

            <Pagination metaData={pagination} onPage={onPagination} />
        </div>
    );
};

export default LandingPageTable;
