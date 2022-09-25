import { getCanonicalLocale } from "@nextcloud/l10n";

/** Get JS date object from dayId */
export function dayIdToDate(dayId: number){
    return new Date(dayId*86400*1000);
}

/** Get month name from number */
export function getShortDateStr(date: Date) {
    return date.toLocaleDateString(getCanonicalLocale(), {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/** Get long date string with optional year if same as current */
export function getLongDateStr(date: Date, skipYear=false, time=false) {
    return date.toLocaleDateString(getCanonicalLocale(), {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: (skipYear && date.getUTCFullYear() === new Date().getUTCFullYear()) ? undefined : 'numeric',
        timeZone: 'UTC',
        hour: time ? 'numeric' : undefined,
        minute: time ? 'numeric' : undefined,
    });
}