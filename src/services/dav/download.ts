import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import { API } from '@services/API';
import * as nativex from '@native';

import type { IPhoto } from '@typings';

/**
 * Download files
 */
export async function downloadFiles(fileIds: number[]) {
  if (!fileIds.length) return;

  const res = await axios.post(API.DOWNLOAD_REQUEST(), { files: fileIds });
  if (res.status !== 200 || !res.data.handle) {
    showError(t('memories', 'Failed to download files'));
    return;
  }

  downloadWithHandle(res.data.handle);
}

/** Get URL to download one file (e.g. for video streaming) */
export function getDownloadLink(photo: IPhoto) {
  return API.STREAM_FILE(photo.fileid);
}

/**
 * Download files with a download handle
 * @param handle Download handle
 */
export function downloadWithHandle(handle: string) {
  return downloadFromUrl(API.DOWNLOAD_FILE(handle));
}

/**
 * Download files from a URL.
 * @param url URL to download from
 */
export function downloadFromUrl(url: string) {
  // Hand off to download manager (absolute URL)
  if (nativex.has()) return nativex.downloadFromUrl(url);

  // Fallback to browser download
  const link = document.createElement('a');
  link.href = url;
  link.download = '';
  link.click();
}
