import axios from '@nextcloud/axios';
import { NAPI, nativex } from './api';
import { addOrigin } from './basic';

/**
 * Download a file from the given URL.
 *
 * The filename is inferred natively from the response Content-Disposition,
 * so no HEAD round trip is needed here.
 *
 * @param url URL to download from
 * @param title Optional label for the completion notification (e.g. album name)
 */
export function downloadFromUrl(url: string = String(), title: string | undefined = undefined) {
  nativex?.downloadFromUrl?.(addOrigin(url), String(), title ?? String());
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
