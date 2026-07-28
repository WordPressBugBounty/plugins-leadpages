// Shown when a metric has no value (e.g. Nova rows before analytics are available).
const EMPTY_METRIC = '-';

export const formatDate = (date: string | null | undefined): string => {
    if (!date) {
        return EMPTY_METRIC;
    }

    // Stored timestamps are UTC. A MySQL DATETIME ("YYYY-MM-DD HH:MM:SS") carries no zone, so we
    // append 'Z'; an ISO value that already has a zone ("...Z" or "+00:00") is used as-is —
    // appending 'Z' to it would produce "...ZZ" and an Invalid Date.
    const hasZone = /(?:Z|[+-]\d\d:?\d\d)$/.test(date);
    const parsed = new Date(hasZone ? date : `${date}Z`);
    if (Number.isNaN(parsed.getTime())) {
        return EMPTY_METRIC;
    }

    const options: Intl.DateTimeFormatOptions = {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
    };

    return parsed.toLocaleString('en-US', options).replace(',', ' AT');
};

export const formatNumber = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return EMPTY_METRIC;
    }
    return Intl.NumberFormat('en-US').format(value);
};

export const formatPercentage = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return EMPTY_METRIC;
    }
    const roundedPercentage = Math.round(value * 100);
    return `${roundedPercentage}%`;
};
