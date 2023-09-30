import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import type { IDay, IPhoto } from './types';
import { API as SAPI } from './services/API';
const euc = encodeURIComponent;

/** Access NativeX over localhost */
const BASE_URL = 'http://127.0.0.1';

/** NativeX asynchronous API */
export const API = {
  /**
   * Local days API.
   * @regex ^/api/days$
   * @returns {IDay[]} for all locally available days.
   */
  DAYS: () => `${BASE_URL}/api/days`,
  /**
   * Local photos API.
   * @regex ^/api/days/\d+$
   * @param dayId Day ID to fetch photos for
   * @returns {IPhoto[]} for all locally available photos for this day.
   */
  DAY: (dayId: number) => `${BASE_URL}/api/days/${dayId}`,

  /**
   * Local photo metadata API.
   * @regex ^/api/image/info/\d+$
   * @param fileId File ID of the photo
   * @returns {IImageInfo} for the given file ID (local).
   */
  IMAGE_INFO: (fileId: number) => `${BASE_URL}/api/image/info/${fileId}`,

  /**
   * Delete files using local fileids.
   * @regex ^/api/image/delete/\d+(,\d+)*$
   * @param fileIds List of AUIDs to delete
   * @param dry (Query) Only check for confirmation and count of local files
   * @returns {void}
   * @throws Return an error code if the user denies the deletion.
   */
  IMAGE_DELETE: (auids: number[]) => `${BASE_URL}/api/image/delete/${auids.join(',')}`,

  /**
   * Local photo preview API.
   * @regex ^/image/preview/\d+$
   * @param fileId File ID of the photo
   * @returns {Blob} JPEG preview of the photo.
   */
  IMAGE_PREVIEW: (fileId: number) => `${BASE_URL}/image/preview/${fileId}`,
  /**
   * Local photo full API.
   * @regex ^/image/full/\d+$
   * @param auid AUID of the photo
   * @returns {Blob} JPEG full image of the photo.
   */
  IMAGE_FULL: (auid: number) => `${BASE_URL}/image/full/${auid}`,

  /**
   * Share a URL with native page.
   * The native client MUST NOT download the object but share the URL directly.
   * @regex ^/api/share/url/.+$
   * @param url URL to share (double-encoded)
   * @returns {void}
   */
  SHARE_URL: (url: string) => `${BASE_URL}/api/share/url/${euc(euc(url))}`,
  /**
   * Share an object (as blob) natively using a given URL.
   * The native client MUST download the object using a download manager
   * and immediately prompt the user to download it. The asynchronous call
   * must return only after the object has been downloaded.
   * @regex ^/api/share/blob/.+$
   * @param url URL to share (double-encoded)
   * @returns {void}
   */
  SHARE_BLOB: (url: string) => `${BASE_URL}/api/share/blob/${euc(euc(url))}`,
  /**
   * Share a local file (as blob) with native page.
   * @regex ^/api/share/local/\d+$
   * @param fileId File ID of the photo
   * @returns {void}
   */
  SHARE_LOCAL: (fileId: number) => `${BASE_URL}/api/share/local/${fileId}`,

  /**
   * Get list of local folders configuration.
   * @regex ^/api/config/local-folders$
   * @returns {LocalFolderConfig[]} List of local folders configuration
   */
  CONFIG_LOCAL_FOLDERS: () => `${BASE_URL}/api/config/local-folders`,
};

/** NativeX synchronous API. */
export type NativeX = {
  /**
   * Check if the native interface is available.
   * @returns Should always return true.
   */
  isNative: () => boolean;

  /**
   * Set the theme color of the app.
   * @param color Color to set
   * @param isDark Whether the theme is dark (for navigation bar)
   */
  setThemeColor: (color: string, isDark: boolean) => void;

  /**
   * Play a tap sound for UI interaction.
   */
  playTouchSound: () => void;

  /**
   * Make a native toast to the user.
   * @param message Message to show
   * @param long Whether the toast should be shown for a long time
   */
  toast: (message: string, long?: boolean) => void;

  /**
   * Start downloading a file from a given URL.
   * @param url URL to download from
   * @param filename Filename to save as
   * @details An error must be shown to the user natively if the download fails.
   */
  downloadFromUrl: (url: string, filename: string) => void;

  /**
   * Play a video from the given AUID or URL(s).
   * @param auid AUID of file (will play local if available)
   * @param fileid File ID of the video (only used for file tracking)
   * @param urlArray JSON-encoded array of URLs to play
   * @details The URL array may contain multiple URLs, e.g. direct playback
   * and HLS separately. The native client must try to play the first URL.
   */
  playVideo: (auid: string, fileid: string, urlArray: string) => void;
  /**
   * Destroy the video player.
   * @param fileid File ID of the video
   * @details The native client must destroy the video player and free up resources.
   * If the fileid doesn't match the playing video, the call must be ignored.
   */
  destroyVideo: (fileid: string) => void;

  /**
   * Set the local folders configuration to show in the timeline.
   * @param json JSON-encoded array of LocalFolderConfig
   */
  configSetLocalFolders: (json: string) => void;

  /**
   * Start the login process
   * @param baseUrl Base URL of the Nextcloud instance
   * @param loginFlowUrl URL to start the login flow
   */
  login: (baseUrl: string, loginFlowUrl: string) => void;

  /**
   * Log out from Nextcloud and delete the tokens.
   */
  logout: () => void;

  /**
   * Reload the app.
   */
  reload: () => void;
};

/** Setting of whether a local folder is enabled */
export type LocalFolderConfig = {
  id: string;
  name: string;
  enabled: boolean;
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
    (document.body.hasAttribute('data-theme-default') && window.matchMedia('(prefers-color-scheme: dark)').matches) ||
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
 * Play a video from the given URL.
 * @param photo Photo to play
 * @param urls URLs to play (remote)
 */
export async function playVideo(photo: IPhoto, urls: string[]) {
  const auid = photo.auid ?? photo.fileid;
  nativex?.playVideo?.(auid.toString(), photo.fileid.toString(), JSON.stringify(urls.map(addOrigin)));
}

/**
 * Destroy the video player.
 */
export async function destroyVideo(photo: IPhoto) {
  nativex?.destroyVideo?.(photo.fileid.toString());
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
  const serverAUIDs = new Set(photos.map((p) => p.auid));

  // Filter out files that are only available locally
  const localOnly = localPhotos.filter((p) => !serverAUIDs.has(p.auid));
  localOnly.forEach((p) => (p.islocal = true));
  photos.push(...localOnly);

  // Sort by epoch value
  photos.sort((a, b) => (b.epoch ?? 0) - (a.epoch ?? 0));
}

/**
 * Request deletion of local photos wherever available.
 * @param photos List of photos to delete
 * @returns The number of photos for which confirmation was received
 * @throws If the request fails
 */
export async function deleteLocalPhotos(photos: IPhoto[], dry: boolean = false): Promise<number> {
  if (!has()) return 0;

  const auids = photos.map((p) => p.auid).filter((a) => !!a) as number[];
  const res = await axios.get(SAPI.Q(API.IMAGE_DELETE(auids), { dry }));
  return res.data.confirms ? res.data.count : 0;
}

/**
 * Get list of local folders configuration.
 * Should be called only if NativeX is available.
 */
export async function getLocalFolders() {
  return (await axios.get<LocalFolderConfig[]>(API.CONFIG_LOCAL_FOLDERS())).data;
}

/**
 * Set list of local folders configuration.
 */
export async function setLocalFolders(config: LocalFolderConfig[]) {
  nativex?.configSetLocalFolders(JSON.stringify(config));
}

/**
 * Log out from Nextcloud and pass ahead.
 */
export async function logout() {
  await axios.get(generateUrl('logout'));
  if (!has()) window.location.reload();
  nativex?.logout();
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
