import axios from '@nextcloud/axios';
import { NAPI, nativex } from './api';
import { addOrigin } from './basic';

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
export async function shareBlobs(
  objects: {
    auid: string;
    href: string;
  }[],
) {
  // Make sure all URLs are absolute
  objects.forEach((obj) => (obj.href = addOrigin(obj.href)));

  // Hand off to native client
  nativex.setShareBlobs(JSON.stringify(objects));
  await axios.get(NAPI.SHARE_BLOBS());
}
