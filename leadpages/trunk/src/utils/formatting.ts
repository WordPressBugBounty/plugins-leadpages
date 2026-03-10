export const formatDate = (date: string): string => {
    const formattedDate = new Date(date + 'Z'); // Append 'Z' to indicate UTC timezone

    const options: Intl.DateTimeFormatOptions = {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
    };

    const displayDate = formattedDate.toLocaleString('en-US', options).replace(',', ' AT');
    return displayDate;
};

export const formatNumber = (value: number): string => {
    return Intl.NumberFormat('en-US').format(value);
};

export const formatPercentage = (value: number): string => {
    const roundedPercentage = Math.round(value * 100);
    return `${roundedPercentage}%`;
};
