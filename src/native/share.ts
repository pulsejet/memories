import axios from '@nextcloud/axios';
import { BASE_URL, NAPI, nativex } from './api';
import { addOrigin } from './basic';
import type { IPhoto } from '@types';

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
 * Share a URL with native page.
 */
export async function shareUrl(url: string) {
  await axios.get(NAPI.SHARE_URL(addOrigin(url)));
}

/**
 * Download a blob from the given URL and share it.
 */
export async function shareBlobFromUrl(url: string) {
  if (url.startsWith(BASE_URL)) {
    throw new Error('Cannot share localhost URL');
  }
  await axios.get(NAPI.SHARE_BLOB(addOrigin(url)));
}

/**
 * Share a local file with native page.
 */
export async function shareLocal(photo: IPhoto) {
  if (!photo.auid) throw new Error('Cannot share local file without AUID');
  await axios.get(NAPI.SHARE_LOCAL(photo.auid));
}
