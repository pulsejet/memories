import { getCanonicalLocale } from '@nextcloud/l10n';
import moment from 'moment';

// Memoize the result of short date conversions
// These operations are surprisingly expensive
// and we do them a lot because of scroller hover
const shortDateStrMemo = new Map<number, string>();

/** Get JS date object from dayId */
export function dayIdToDate(dayId: number) {
  return new Date(dayId * 86400 * 1000);
}

/** Get Day ID from JS date */
export function dateToDayId(date: Date) {
  return Math.floor(date.getTime() / (86400 * 1000));
}

/** Get month name from number */
export function getShortDateStr(date: Date) {
  const dayId = dateToDayId(date);
  if (!shortDateStrMemo.has(dayId)) {
    shortDateStrMemo.set(
      dayId,
      date.toLocaleDateString(getCanonicalLocale(), {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
      })
    );
  }
  return shortDateStrMemo.get(dayId);
}

/** Get long date string with optional year if same as current */
export function getLongDateStr(date: Date, skipYear = false, time = false) {
  return date.toLocaleDateString(getCanonicalLocale(), {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: skipYear && date.getUTCFullYear() === new Date().getUTCFullYear() ? undefined : 'numeric',
    timeZone: 'UTC',
    hour: time ? 'numeric' : undefined,
    minute: time ? 'numeric' : undefined,
  });
}

/** Get month and year string */
export function getMonthDateStr(date: Date) {
  return date.toLocaleDateString(getCanonicalLocale(), {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  });
}

/** Get text like "5 years ago" from a date */
export function getFromNowStr(date: Date) {
  // Get fromNow in correct locale
  const text = moment(date).locale(getCanonicalLocale()).fromNow();

  // Title case
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Convert number of seconds to time string */
export function getDurationStr(sec: number) {
  let hours = Math.floor(sec / 3600);
  let minutes: number | string = Math.floor((sec - hours * 3600) / 60);
  let seconds: number | string = sec - hours * 3600 - minutes * 60;

  if (seconds < 10) {
    seconds = '0' + seconds;
  }

  if (hours > 0) {
    if (minutes < 10) {
      minutes = '0' + minutes;
    }
    return `${hours}:${minutes}:${seconds}`;
  }

  return `${minutes}:${seconds}`;
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
