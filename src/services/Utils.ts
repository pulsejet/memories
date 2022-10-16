import { getCanonicalLocale } from "@nextcloud/l10n";
import { IPhoto } from "../types";

// Memoize the result of short date conversions
// These operations are surprisingly expensive
// and we do them a lot because of scroller hover
const shortDateStrMemo = new Map<number, string>();

/** Get JS date object from dayId */
export function dayIdToDate(dayId: number){
    return new Date(dayId*86400*1000);
}

/** Get Day ID from JS date */
export function dateToDayId(date: Date){
    return Math.floor(date.getTime() / (86400*1000));
}

/** Get month name from number */
export function getShortDateStr(date: Date) {
    const dayId = dateToDayId(date);
    if (!shortDateStrMemo.has(dayId)) {
        shortDateStrMemo.set(dayId,
            date.toLocaleDateString(getCanonicalLocale(), {
                month: 'short',
                year: 'numeric',
                timeZone: 'UTC',
            }));
    }
    return shortDateStrMemo.get(dayId);
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

/**
 * Search for elem in a sorted array of objects
 * If the object is not found, return the index where it should be inserted
 *
 * @param arr Array of objects to search
 * @param elem Element to search for
 * @param key Key to use for comparison
 */
export function binarySearch(arr: any, elem: any, key?: string) {
    let minIndex = 0;
    let maxIndex = arr.length - 1;
    let currentIndex: number;
    let currentElement: any;

    while (minIndex <= maxIndex) {
        currentIndex = (minIndex + maxIndex) / 2 | 0;
        currentElement = key ? arr[currentIndex][key] : arr[currentIndex];

        if (currentElement < elem) {
            minIndex = currentIndex + 1;
        }
        else if (currentElement > elem) {
            maxIndex = currentIndex - 1;
        }
        else {
            return currentIndex;
        }
    }

    return minIndex;
}

/**
 * Round a number to N decimal places
 * @param num Number to round
 * @param places Number of decimal places
 */
export function round(num: number, places: number) {
    const pow = Math.pow(10, places);
    return Math.round(num * pow) / pow;
}

/**
 * Round to nearest 0.5. Useful for pixels.
 * @param num Number to round
 */
export function roundHalf(num: number) {
    return Math.round(num * 2) / 2;
}

/**
 * Convert server-side flags to bitmask
 * @param photo Photo to process
 */
export function convertFlags(photo: IPhoto) {
    if (photo.isvideo) {
        photo.flag |= constants.c.FLAG_IS_VIDEO;
        delete photo.isvideo;
    }
    if (photo.isfavorite) {
        photo.flag |= constants.c.FLAG_IS_FAVORITE;
        delete photo.isfavorite;
    }
    if (photo.isfolder) {
        photo.flag |= constants.c.FLAG_IS_FOLDER;
        delete photo.isfolder;
    }
    if (photo.isface) {
        photo.flag |= constants.c.FLAG_IS_FACE;
        delete photo.isface;
    }
    if (photo.istag) {
        photo.flag |= constants.c.FLAG_IS_TAG;
        delete photo.istag;
    }
}

/** Global constants */
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