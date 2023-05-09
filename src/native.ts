import axios from '@nextcloud/axios';
import type { IDay, IPhoto } from './types';

const BASE_URL = 'http://127.0.0.1';

export const API = {
  DAYS: () => `${BASE_URL}/api/days`,
  DAY: (dayId: number) => `${BASE_URL}/api/days/${dayId}`,

  IMAGE_PREVIEW: (fileId: number) => `${BASE_URL}/image/preview/${fileId}`,
  IMAGE_FULL: (fileId: number) => `${BASE_URL}/image/full/${fileId}`,
  IMAGE_INFO: (fileId: number) => `${BASE_URL}/image/info/${fileId}`,
};

/**
 * Native interface for the Android app.
 */
export type NativeX = {
  isNative: () => boolean;
  setThemeColor: (color: string, isDark: boolean) => void;
  downloadFromUrl: (url: string, filename: string) => void;
};

/** The native interface is a global object that is injected by the native app. */
const nativex: NativeX = globalThis.nativex;

/**
 * @returns Whether the native interface is available.
 */
export const has = () => !!nativex;

/**
 * Change the theme color of the app to default.
 */
export const setTheme = (color?: string, dark?: boolean) => {
  if (!has()) return;

  color ??= getComputedStyle(document.body).getPropertyValue('--color-main-background');
  dark ??=
    window.matchMedia('(prefers-color-scheme: dark)').matches ||
    document.body.hasAttribute('data-theme-dark') ||
    document.body.hasAttribute('data-theme-dark-highcontrast');
  nativex?.setThemeColor?.(color, dark);
};

/**
 * Download a file from the given URL.
 */
export const downloadFromUrl = async (url: string) => {
  // Make HEAD request to get filename
  const res = await axios.head(url);
  let filename = res.headers['content-disposition'];
  if (res.status !== 200 || !filename) return;

  // Extract filename from header without quotes
  filename = filename.split('filename="')[1].slice(0, -1);

  // Hand off to download manager
  nativex?.downloadFromUrl?.(url, filename);
};

/**
 * Extend a list of days with local days.
 * Fetches the local days from the native interface.
 */
export async function extendDaysWithLocal(days: IDay[]) {
  if (!has()) return;

  // Query native part
  const res = await fetch(API.DAYS());
  if (!res.ok) return;
  const local: IDay[] = await res.json();
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

  // Query native part
  const res = await fetch(API.DAY(dayId));
  if (!res.ok) return;

  // Merge local photos into remote photos
  const localPhotos: IPhoto[] = await res.json();
  const photosSet = new Set(photos.map((p) => p.basename));
  const localOnly = localPhotos.filter((p) => !photosSet.has(p.basename));
  localOnly.forEach((p) => (p.islocal = true));
  photos.push(...localOnly);
}
