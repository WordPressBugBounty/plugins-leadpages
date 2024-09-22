import type { LandingPage } from '../components/PageTable';
import type { Direction, OrderBy } from '../types/table';

export const handleSortChange = (
    column: keyof LandingPage,
    direction: Direction,
    onSortChange: (orderBy: OrderBy, direction: Direction) => void,
    setShowCaret: React.Dispatch<React.SetStateAction<boolean>>
) => {
    let orderBy: OrderBy = 'name';
    if (column === 'last_published') {
        orderBy = 'date';
    }
    onSortChange(orderBy, direction);
    setShowCaret(true);
};
