import type { IDay, IPhoto } from './types';

/**
 * Type of a native promise (this will be the exact type in Java).
 */
type NativePromise<T> = (call: string, arg: T) => void;

/**
 * Native interface for the Android app.
 */
export type NativeX = {
  isNative: () => boolean;
  setThemeColor: (color: string, isDark: boolean) => void;
  getLocalDays: NativePromise<string>;
  getLocalByDayId: NativePromise<string>;
  getJpeg: NativePromise<string>;
};

/** The native interface is a global object that is injected by the native app. */
const nativex: NativeX = globalThis.nativex;

/** List of promises that are waiting for a native response. */
const nativePromises = new Map<string, object>();

/**
 * Wraps a native function in a promise.
 * JavascriptInterface doesn't support async functions, so we have to do this manually.
 * The native function should call `window.nativexr(call, resolve, reject)` when it's done.
 *
 * @param fun Function to promisify
 * @param binary Whether the response is binary (will not be decoded)
 */
function nativePromisify<A, T>(fun: NativePromise<A>, binary = false): (arg: A) => Promise<T> {
  if (!fun) {
    return () => {
      return new Promise((_, reject) => {
        reject('Native function not available');
      });
    };
  }

  return (arg: A) => {
    return new Promise((resolve, reject) => {
      const call = Math.random().toString(36).substring(7);
      nativePromises.set(call, { resolve, reject, binary });
      fun(call, arg);
    });
  };
}

/**
 * Registers the global handler for native responses.
 * This should be called by the native app when it's ready to resolve a promise.
 *
 * @param call ID passed to native function
 * @param resolve Response from native function
 * @param reject Rejection from native function
 */
globalThis.nativexr = (call: string, resolve?: string, reject?: string) => {
  const promise = nativePromises.get(call);
  if (!promise) {
    console.error('No promise found for call', call);
    return;
  }

  if (resolve !== undefined) {
    if (!(promise as any).binary) resolve = window.atob(resolve);
    (promise as any).resolve(resolve);
  } else if (reject !== undefined) {
    (promise as any).reject(window.atob(reject));
  } else {
    console.error('No resolve or reject found for call', call);
    return;
  }

  nativePromises.delete(call);
};

/**
 * @returns Whether the native interface is available.
 */
export const has = () => !!nativex;

/**
 * Change the theme color of the app.
 */
export const setThemeColor: typeof nativex.setThemeColor = nativex?.setThemeColor.bind(nativex);

/**
 * Gets the local days array.
 *
 * @returns List of local days (JSON string)
 */
const getLocalDays = nativePromisify<number, string>(nativex?.getLocalDays.bind(nativex));

/**
 * Gets the local photos for a day with a dayId.
 *
 * @param dayId Day ID to get photos for
 * @returns List of local photos (JSON string)
 */
const getLocalByDayId = nativePromisify<number, string>(nativex?.getLocalByDayId.bind(nativex));

/**
 * Gets the JPEG data for a photo using a local URI.
 *
 * @param url Local URI to get JPEG data for
 * @returns JPEG data (base64 string)
 */
const getJpeg = nativePromisify<string, string>(nativex?.getJpeg.bind(nativex), true);

/**
 * Extend a list of days with local days.
 * Fetches the local days from the native interface.
 */
export async function extendDaysWithLocal(days: IDay[]) {
  if (!has()) return;

  // Query native part
  const local: IDay[] = JSON.parse(await getLocalDays(0));
  const remoteMap = new Map(days.map((d) => [d.dayid, d]));

  // Merge local days into remote days
  for (const day of local) {
    const remote = remoteMap.get(day.dayid);
    if (remote) {
      remote.count = Math.max(remote.count, day.count);
    } else {
      days.push(day);
    }
  }

  // TODO: sort depends on view
  // (but we show it for only timeline anyway for now)
  days.sort((a, b) => b.dayid - a.dayid);
}

/**
 * Extend a list of photos with local photos.
 * Fetches the local photos from the native interface and filters out duplicates.
 *
 * @param dayId Day ID to append local photos to
 * @param photos List of photos to append to (duplicates will not be added)
 * @returns
 */
export async function extendDayWithLocal(dayId: number, photos: IPhoto[]) {
  if (!has()) return;

  const localPhotos: IPhoto[] = JSON.parse(await getLocalByDayId(dayId));
  const photosSet = new Set(photos.map((p) => p.basename));
  const localOnly = localPhotos.filter((p) => !photosSet.has(p.basename));
  localOnly.forEach((p) => (p.islocal = true));
  photos.push(...localOnly);
}

/**
 * Gets the JPEG data URI for a photo using a native URI.
 *
 * @param url Native URI to get JPEG data for
 * @returns Data URI for JPEG
 */
export async function getJpegDataUri(url: string) {
  const image = await getJpeg(url);
  return `data:image/jpeg;base64,${image}`;
}

/**
 * Checks whether a URL is a native URI (nativex://).
 *
 * @param url URL to check
 */
export function IS_NATIVE_URL(url: string) {
  return url.startsWith('nativex://');
}

/**
 * Get a downsized preview URL for a native file ID.
 *
 * @param fileid Local file ID returned by native interface
 * @returns native URI
 */
export function NATIVE_URL_PREVIEW(fileid: number) {
  return `nativex://preview/${fileid}`;
}

/**
 * Get a full sized URL for a native file ID.
 *
 * @param fileid Local file ID returned by native interface
 * @returns native URI
 */
export function NATIVE_URL_FULL(fileid: number) {
  return `nativex://full/${fileid}`;
}
