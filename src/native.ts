import axios from '@nextcloud/axios';
import type { IDay, IPhoto } from './types';
import { constants } from './services/Utils';

const BASE_URL = 'http://127.0.0.1';

const euc = encodeURIComponent;

export const API = {
  DAYS: () => `${BASE_URL}/api/days`,
  DAY: (dayId: number) => `${BASE_URL}/api/days/${dayId}`,
  IMAGE_INFO: (fileId: number) => `${BASE_URL}/api/image/info/${fileId}`,
  IMAGE_DELETE: (fileIds: number[]) => `${BASE_URL}/api/image/delete/${fileIds.join(',')}`,

  IMAGE_PREVIEW: (fileId: number) => `${BASE_URL}/image/preview/${fileId}`,
  IMAGE_FULL: (fileId: number) => `${BASE_URL}/image/full/${fileId}`,

  SHARE_URL: (url: string) => `${BASE_URL}/api/share/url/${euc(euc(url))}`,
  SHARE_BLOB: (url: string) => `${BASE_URL}/api/share/blob/${euc(euc(url))}`,
  SHARE_LOCAL: (fileId: number) => `${BASE_URL}/api/share/local/${fileId}`,
};

/**
 * Native interface for the Android app.
 */
export type NativeX = {
  isNative: () => boolean;
  setThemeColor: (color: string, isDark: boolean) => void;
  downloadFromUrl: (url: string, filename: string) => void;
  playTouchSound: () => void;

  playVideoLocal: (fileid: string) => void;
  playVideoHls: (fileid: string, url: string) => void;
  destroyVideo: (fileid: string) => void;
};

/** The native interface is a global object that is injected by the native app. */
const nativex: NativeX = globalThis.nativex;

/**
 * @returns Whether the native interface is available.
 */
export function has() {
  return !!nativex;
}

/**
 * Change the theme color of the app to default.
 */
export async function setTheme(color?: string, dark?: boolean) {
  if (!has()) return;

  color ??= getComputedStyle(document.body).getPropertyValue('--color-main-background');
  dark ??=
    window.matchMedia('(prefers-color-scheme: dark)').matches ||
    document.body.hasAttribute('data-theme-dark') ||
    document.body.hasAttribute('data-theme-dark-highcontrast');
  nativex?.setThemeColor?.(color, dark);
}

/**
 * Download a file from the given URL.
 */
export async function downloadFromUrl(url: string) {
  // Make HEAD request to get filename
  const res = await axios.head(url);
  let filename = res.headers['content-disposition'];
  if (res.status !== 200 || !filename) return;

  // Extract filename from header without quotes
  filename = filename.split('filename="')[1].slice(0, -1);

  // Hand off to download manager
  nativex?.downloadFromUrl?.(addOrigin(url), filename);
}

/**
 * Play touch sound.
 */
export async function playTouchSound() {
  nativex?.playTouchSound?.();
}

/**
 * Play a video from the given file ID (local file).
 */
export async function playVideoLocal(fileid: number) {
  nativex?.playVideoLocal?.(fileid.toString());
}

/**
 * Play a video from the given URL (HLS stream).
 */
export async function playVideoHls(fileid: number, url: string) {
  nativex?.playVideoHls?.(fileid.toString(), addOrigin(url));
}

/**
 * Destroy the video player.
 */
export async function destroyVideo(fileId: number) {
  nativex?.destroyVideo?.(fileId.toString());
}

/**
 * Share a URL with native page.
 */
export async function shareUrl(url: string) {
  await axios.get(API.SHARE_URL(addOrigin(url)));
}

/**
 * Download a blob from the given URL and share it.
 */
export async function shareBlobFromUrl(url: string) {
  if (url.startsWith(BASE_URL)) {
    throw new Error('Cannot share localhost URL');
  }
  await axios.get(API.SHARE_BLOB(addOrigin(url)));
}

/**
 * Share a local file with native page.
 */
export async function shareLocal(fileId: number) {
  await axios.get(API.SHARE_LOCAL(fileId));
}

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

  // Sort by datetaken
  photos.sort((a, b) => (b.datetaken ?? 0) - (a.datetaken ?? 0));
}

/**
 * Request deletion of local photos wherever available.
 * @param photos List of photos to delete
 * @returns List of photos that were deleted
 * @throws If the request fails
 */
export async function deleteLocalPhotos(photos: IPhoto[]): Promise<IPhoto[]> {
  if (!has()) return [];

  const localPhotos = photos.filter((p) => p.flag & constants.c.FLAG_IS_LOCAL);
  if (localPhotos.length > 0) {
    const fileids = localPhotos.map((p) => p.fileid);
    await axios.get(API.IMAGE_DELETE(fileids));
  }

  return localPhotos;
}

/**
 * Add current origin to URL if doesn't have any protocol or origin.
 */
function addOrigin(url: string) {
  return url.match(/^(https?:)?\/\//)
    ? url
    : url.startsWith('/')
    ? `${location.origin}${url}`
    : `${location.origin}/${url}`;
}
