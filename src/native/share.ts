import axios from '@nextcloud/axios';
import { NAPI, nativex } from './api';
import { addOrigin } from './basic';

/** Download a file; the filename comes from the response Content-Disposition. */
export function downloadFromUrl(url: string) {
  nativex?.downloadFromUrl?.(addOrigin(url), '');
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
