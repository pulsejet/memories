import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';
import type { IHeadRow } from '@typings';

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
export function getShortDateStr(date: Date): string {
  const dayId = dateToDayId(date);
  if (!shortDateStrMemo.has(dayId)) {
    shortDateStrMemo.set(
      dayId,
      date.toLocaleDateString(getCanonicalLocale(), {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
      }),
    );
  }
  return shortDateStrMemo.get(dayId)!;
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

/**
 * Get the EXIF date string from a datetaken object
 * @param date The datetaken value from photo metadata (UTC relative date)
 * @returns YYYY:MM:DD HH:MM:SS
 */
export function getExifDateStr(date: Date) {
  const year = date.getUTCFullYear().toString().padStart(4, '0');
  const month = (date.getUTCMonth() + 1).toString().padStart(2, '0');
  const day = date.getUTCDate().toString().padStart(2, '0');
  const hour = date.getUTCHours().toString().padStart(2, '0');
  const minute = date.getUTCMinutes().toString().padStart(2, '0');
  const second = date.getUTCSeconds().toString().padStart(2, '0');
  return `${year}:${month}:${day} ${hour}:${minute}:${second}`;
}

/**
 * Get text like "5 years ago" from a date.
 *
 * @param date The date to convert
 * @param opts.padding The number of *days* to pad
 *
 * @returns A string like "5 years ago"
 */
export function getFromNowStr(date: Date, opts?: { padding?: number }) {
  // Get fromNow in correct locale
  const text =
    DateTime.fromJSDate(date).toRelative({
      locale: getCanonicalLocale(),
      padding: (opts?.padding ?? 0) * 24 * 60 * 60 * 1000, // 10 days
    }) ?? 'Unknown';

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
 * Get the header text from a row header
 * @param head Head row object
 */
export function getHeadRowName(head: IHeadRow): string {
  // Check cache
  if (head.name) return head.name;

  // Make date string
  // The reason this function is separate from processDays is
  // because this call is terribly slow even on desktop
  const dateTaken = dayIdToDate(head.dayId);
  let name: string;
  if (head.ismonth) {
    name = getMonthDateStr(dateTaken);
  } else {
    name = getLongDateStr(dateTaken, true);
  }

  // Cache and return
  return (head.name = name);
}
