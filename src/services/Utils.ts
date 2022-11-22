import { getCanonicalLocale } from "@nextcloud/l10n";
import { getCurrentUser } from "@nextcloud/auth";
import { loadState } from "@nextcloud/initial-state";
import { IPhoto } from "../types";
import moment from "moment";

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
        month: "short",
        year: "numeric",
        timeZone: "UTC",
      })
    );
  }
  return shortDateStrMemo.get(dayId);
}

/** Get long date string with optional year if same as current */
export function getLongDateStr(date: Date, skipYear = false, time = false) {
  return date.toLocaleDateString(getCanonicalLocale(), {
    weekday: "short",
    month: "short",
    day: "numeric",
    year:
      skipYear && date.getUTCFullYear() === new Date().getUTCFullYear()
        ? undefined
        : "numeric",
    timeZone: "UTC",
    hour: time ? "numeric" : undefined,
    minute: time ? "numeric" : undefined,
  });
}

/** Get month and year string */
export function getMonthDateStr(date: Date) {
  return date.toLocaleDateString(getCanonicalLocale(), {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
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
    seconds = "0" + seconds;
  }

  if (hours > 0) {
    if (minutes < 10) {
      minutes = "0" + minutes;
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

/**
 * Search for elem in a sorted array of objects
 * If the object is not found, return the index where it should be inserted
 *
 * @param arr Array of objects to search
 * @param elem Element to search for
 * @param key Key to use for comparison
 */
export function binarySearch(arr: any, elem: any, key?: string) {
  if (arr.length === 0) return 0;

  const desc = key
    ? arr[0][key] > arr[arr.length - 1][key]
    : arr[0] > arr[arr.length - 1];

  let minIndex = 0;
  let maxIndex = arr.length - 1;
  let currentIndex: number;
  let currentElement: any;

  while (minIndex <= maxIndex) {
    currentIndex = ((minIndex + maxIndex) / 2) | 0;
    currentElement = key ? arr[currentIndex][key] : arr[currentIndex];

    const e1 = desc ? elem : currentElement;
    const e2 = desc ? currentElement : elem;

    if (e1 < e2) {
      minIndex = currentIndex + 1;
    } else if (e1 > e2) {
      maxIndex = currentIndex - 1;
    } else {
      return currentIndex;
    }
  }

  return minIndex;
}

/**
 * Round a number to N decimal places
 * @param num Number to round
 * @param places Number of decimal places
 * @param floor If true, round down instead of to nearest
 */
export function round(num: number, places: number, floor = false) {
  const pow = Math.pow(10, places);
  const int = num * pow;
  return (floor ? Math.floor : Math.round)(int) / pow;
}

/**
 * Round to nearest 0.5. Useful for pixels.
 * @param num Number to round
 */
export function roundHalf(num: number) {
  return Math.round(num * 2) / 2;
}

/** Choose a random element from an array */
export function randomChoice(arr: any[]) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/**
 * Choose a random sub array from an array
 * https://stackoverflow.com/a/11935263/4745239
 */
export function randomSubarray(arr: any[], size: number) {
  if (arr.length <= size) return arr;
  var shuffled = arr.slice(0),
    i = arr.length,
    min = i - size,
    temp,
    index;
  while (i-- > min) {
    index = Math.floor((i + 1) * Math.random());
    temp = shuffled[index];
    shuffled[index] = shuffled[i];
    shuffled[i] = temp;
  }
  return shuffled.slice(min);
}

/**
 * Convert server-side flags to bitmask
 * @param photo Photo to process
 */
export function convertFlags(photo: IPhoto) {
  if (typeof photo.flag === "undefined") {
    photo.flag = 0; // flags
  }

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
  if (photo.isalbum) {
    photo.flag |= constants.c.FLAG_IS_ALBUM;
    delete photo.isalbum;
  }
}

/**
 * Get the path of the folder on folders route
 * This function does not check if this is the folder route
 */
export function getFolderRoutePath(basePath: string) {
  let path: any = vuerouter.currentRoute.params.path || "/";
  path = typeof path === "string" ? path : path.join("/");
  path = basePath + "/" + path;
  path = path.replace(/\/\/+/, "/"); // Remove double slashes
  return path;
}

/**
 * Get route hash for viewer for photo
 */
export function getViewerHash(photo: IPhoto) {
  return `#v/${photo.dayid}/${photo.key}`;
}

/** Set a timer that renews if existing */
export function setRenewingTimeout(
  ctx: any,
  name: string,
  callback: () => void | null,
  delay: number
) {
  if (ctx[name]) window.clearTimeout(ctx[name]);
  ctx[name] = window.setTimeout(() => {
    ctx[name] = 0;
    callback?.();
  }, delay);
}

// Outside for set
const TagDayID = {
  START: -(1 << 30),
  FOLDERS: -(1 << 30) + 1,
  TAGS: -(1 << 30) + 2,
  FACES: -(1 << 30) + 3,
  ALBUMS: -(1 << 30) + 4,
};

/** Global constants */
export const constants = {
  c: {
    FLAG_PLACEHOLDER: 1 << 0,
    FLAG_LOAD_FAIL: 1 << 1,
    FLAG_IS_VIDEO: 1 << 2,
    FLAG_IS_FAVORITE: 1 << 3,
    FLAG_IS_FOLDER: 1 << 4,
    FLAG_IS_TAG: 1 << 5,
    FLAG_IS_FACE: 1 << 6,
    FLAG_IS_ALBUM: 1 << 7,
    FLAG_SELECTED: 1 << 8,
    FLAG_LEAVING: 1 << 9,
  },

  TagDayID: TagDayID,
  TagDayIDValueSet: new Set(Object.values(TagDayID)),
};

/** Cache store */
let staticCache: Cache | null = null;
const cacheName = `memories-${loadState("memories", "version")}-${
  getCurrentUser()?.uid
}`;
openCache().then((cache) => {
  staticCache = cache;
});

// Clear all caches except the current one
window.caches?.keys().then((keys) => {
  keys
    .filter((key) => key.startsWith("memories-") && key !== cacheName)
    .forEach((key) => {
      window.caches.delete(key);
    });
});

/** Open the cache */
export async function openCache() {
  try {
    return await window.caches?.open(cacheName);
  } catch {
    console.warn("Failed to get cache", cacheName);
    return null;
  }
}

/** Get data from the cache */
export async function getCachedData<T>(url: string): Promise<T> {
  if (!window.caches) return null;
  const cache = staticCache || (await openCache());
  if (!cache) return null;

  const cachedResponse = await cache.match(url);
  if (!cachedResponse || !cachedResponse.ok) return undefined;
  return await cachedResponse.json();
}

/** Store data in the cache */
export function cacheData(url: string, data: Object) {
  if (!window.caches) return;
  const str = JSON.stringify(data);

  (async () => {
    const cache = staticCache || (await openCache());
    if (!cache) return;

    const response = new Response(str);
    const encoded = new TextEncoder().encode(str);
    response.headers.set("Content-Type", "application/json");
    response.headers.set("Content-Length", encoded.length.toString());
    response.headers.set("Cache-Control", "max-age=604800"); // 1 week
    response.headers.set("Vary", "Accept-Encoding");
    await cache.put(url, response);
  })();
}
