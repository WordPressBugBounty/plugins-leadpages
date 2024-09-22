import { Button } from '@wordpress/components';
import { chevronLeft, chevronRight } from '@wordpress/icons';
import type { MetaData } from '../../types/api';
import './pagination.css';

export interface Props {
    metaData: MetaData;
    onPage: (page: number, perPage: number) => void;
}

const renderPageSizeDropdown = (perPage: number, onPage: (page: number, perPage: number) => void) => {
    const handlePageSizeChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const newPerPage = parseInt(e.target.value);
        onPage(1, newPerPage);
    };

    const pageSizeOptions = [20, 50, 100];

    return (
        <select
            className="lp-page-size-dropdown"
            value={perPage}
            onChange={handlePageSizeChange}
            aria-label="Select Page Size"
        >
            {pageSizeOptions.map((option) => (
                <option key={option} value={option}>
                    {option}
                </option>
            ))}
        </select>
    );
};

const Pagination: React.FC<Props> = ({ metaData, onPage }) => {
    let { total, page, perPage } = metaData;
    const totalPages = Math.ceil(total / perPage); // perPage should never be zero
    return (
        <nav className="lp-pagination">
            {total} {total === 1 ? 'item' : 'items'}
            <Button
                aria-label="go to previous page"
                size="small"
                disabled={page <= 1}
                onClick={() => onPage(--page, perPage)}
                icon={chevronLeft}
            />
            <span className="lp-page-count-page">{page}</span>&nbsp;of {totalPages}
            <Button
                aria-label="go to next page"
                size="small"
                disabled={page >= totalPages}
                onClick={() => onPage(++page, perPage)}
                icon={chevronRight}
            />
            {renderPageSizeDropdown(perPage, onPage)}
        </nav>
    );
};

export default Pagination;
