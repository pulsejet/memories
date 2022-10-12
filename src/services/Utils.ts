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
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: (skipYear && date.getUTCFullYear() === new Date().getUTCFullYear()) ? undefined : 'numeric',
        timeZone: 'UTC',
        hour: time ? 'numeric' : undefined,
        minute: time ? 'numeric' : undefined,
    });
}

/**
 * Returns a hash code from a string
 * @param  {String} str The string to hash.
 * @return {Number}    A 32bit integer
 * @see http://werxltd.com/wp/2010/05/13/javascript-implementation-of-javas-string-hashcode-method/
 */
 export function hashCode(str: string): number {
    let hash = 0;
    for (let i = 0, len = str.length; i < len; i++) {
        let chr = str.charCodeAt(i);
        hash = (hash << 5) - hash + chr;
        hash |= 0; // Convert to 32bit integer
    }
    return hash;
}

export const constants = {
    c: {
        FLAG_PLACEHOLDER:   1 << 0,
        FLAG_LOADED:        1 << 1,
        FLAG_LOAD_FAIL:     1 << 2,
        FLAG_IS_VIDEO:      1 << 3,
        FLAG_IS_FAVORITE:   1 << 4,
        FLAG_IS_FOLDER:     1 << 5,
        FLAG_IS_TAG:        1 << 6,
        FLAG_IS_FACE:       1 << 7,
        FLAG_SELECTED:      1 << 8,
        FLAG_LEAVING:       1 << 9,
        FLAG_EXIT_LEFT:     1 << 10,
        FLAG_ENTER_RIGHT:   1 << 11,
        FLAG_FORCE_RELOAD:  1 << 12,
    },

    TagDayID: {
        START:          -(1 << 30),
        FOLDERS:        -(1 << 30) + 1,
        TAGS:           -(1 << 30) + 2,
        FACES:          -(1 << 30) + 3,
    },
}