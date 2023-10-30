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

/**
 * Download files with a download handle
 * @param handle Download handle
 */
export function downloadWithHandle(handle: string) {
  const url = API.DOWNLOAD_FILE(handle);

  // Hand off to download manager (absolute URL)
  if (nativex.has()) return nativex.downloadFromUrl(url);

  // Fallback to browser download
  window.location.href = url;
}

/**
 * Download the files given by the fileIds
 * @param photos list of photos
 */
export async function downloadFilesByPhotos(photos: IPhoto[]) {
  await downloadFiles(photos.map((f) => f.fileid));
}

/** Get URL to download one file (e.g. for video streaming) */
export function getDownloadLink(photo: IPhoto) {
  return API.STREAM_FILE(photo.fileid);
}
