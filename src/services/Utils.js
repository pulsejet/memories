/** Get JS date object from dayId */
export function dayIdToDate(dayId){
    return new Date(Number(dayId)*86400*1000);
}

/** Get month name from number */
export function getMonthName(date) {
    const dateTimeFormat = new Intl.DateTimeFormat('en-US', {
        month: 'short',
        timeZone: 'UTC',
    });
    return dateTimeFormat.formatToParts(date)[0].value;
}