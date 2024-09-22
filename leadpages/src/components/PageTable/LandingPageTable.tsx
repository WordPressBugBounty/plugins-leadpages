import { useState } from '@wordpress/element';
import { escapeHTML } from '@wordpress/escape-html';
import Caret from '../Caret';
import Pagination from '../Pagination';
import { handleSortChange } from '../../utils/sorting';
import { BUILDER_URL, LEADPAGES_URL, HOME_URL } from '../../utils/config';
import { formatDate, formatNumber, formatPercentage } from '../../utils/formatting';
import { OrderBy, Direction } from '../../types/table';
import { MetaData, LandingPage } from '../../types/api';
import './landing_page_table.css';

export const columnDisplayNames: Partial<Record<keyof LandingPage, string>> = {
    name: 'PAGE NAME',
    visitors: 'UNIQUE VISITORS',
    conversion_rate: 'CONVERSION RATE',
    last_published: 'LAST MODIFIED',
    wp_slug: 'SLUG',
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
    const editLPBuilderUrl = isSplitTest
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

const generateColumnURL = (column: keyof LandingPage, page: LandingPage): string => {
    const isSplitTest = page.kind === 'LeadpageSplitTestV2';
    const pageAnalyticsUrl = isSplitTest
        ? `${LEADPAGES_URL}#/split-test-analytics/${page.uuid}`
        : `${LEADPAGES_URL}#/pages/${page.uuid}/analytics/`;

    switch (column) {
        case 'wp_slug':
            return HOME_URL + '/' + page.wp_slug;
        case 'visitors':
            return `${pageAnalyticsUrl}?metric=unique_views`;
        case 'conversion_rate':
            return `${pageAnalyticsUrl}?metric=conversion_rate`;
        default:
            return '';
    }
};

const generateActionItems = (
    actions: string[],
    page: LandingPage,
    onAction: (action: string, page: LandingPage) => void
) => {
    return actions.map((action, actionIndex) => {
        const { label, url, type, className, handler } = getActionItem(action, page, onAction);
        if (type === 'link') {
            return (
                <a key={actionIndex} href={url} className={className} target="_blank" rel="noopener noreferrer">
                    {label}
                </a>
            );
        } else if (type === 'function') {
            return (
                <button key={actionIndex} onClick={handler} className={className}>
                    {label}
                </button>
            );
        }
        return null;
    });
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

    const renderColumnData = (column: keyof LandingPage, page: LandingPage) => {
        const url = generateColumnURL(column, page);

        switch (column) {
            case 'name':
                return (
                    <>
                        <div className="page-name">{escapeHTML(page[column])}</div>
                        <div className="actions">{generateActionItems(actions, page, onAction)}</div>
                    </>
                );
            case 'last_published':
                return <>{formatDate(page[column])}</>;
            case 'visitors':
                return (
                    <a
                        key={`${column}-${page.uuid}`}
                        href={url}
                        className="action-link"
                        target="_blank"
                        rel="noreferrer"
                    >
                        {formatNumber(page[column])}
                    </a>
                );
            case 'conversion_rate':
                return (
                    <a
                        key={`${column}-${page.uuid}`}
                        href={url}
                        className="action-link"
                        target="_blank"
                        rel="noreferrer"
                    >
                        {formatPercentage(page[column])}
                    </a>
                );
            case 'wp_slug':
                return (
                    <a
                        key={`${column}-${page.uuid}`}
                        href={url}
                        className="action-link"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <div className="page-wp_slug">/{escapeHTML(page[column])}</div>
                    </a>
                );
            default:
                return escapeHTML(page[column] as string);
        }
    };

    return (
        <>
            <Pagination metaData={pagination} onPage={onPagination} />
            <table className="table">
                {/* Table header display */}
                <thead>
                    <tr>
                        {columns.map((column, index) => {
                            return (
                                <th key={index} className={`column-header column-${column}`}>
                                    {isSortableColumn(column) ? (
                                        // Render sortable column headers as a button with Caret
                                        <button className="sortable-header" onClick={() => handleColumnSort(column)}>
                                            {columnDisplayNames[column]}
                                            {showCaret && sortData.orderBy === column && (
                                                <Caret up={sortData.direction === 'asc'} />
                                            )}
                                        </button>
                                    ) : (
                                        // Render other column headers normally
                                        columnDisplayNames[column]
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                {/* Data display in table */}
                <tbody>
                    {pages.map((page, index) => (
                        <tr key={index}>
                            {columns.map((column, columnIndex) => (
                                <td key={columnIndex} className="table-data">
                                    {renderColumnData(column, page)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
};

export default LandingPageTable;
