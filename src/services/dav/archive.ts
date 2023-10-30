import * as base from './base';

import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { translate as t } from '@services/l10n';
import { API } from '@services/API';

import type { IPhoto } from '@typings';

/**
 * Archive or unarchive a single file
 *
 * @param fileid File id
 * @param archive Archive or unarchive
 */
async function archiveFile(fileid: number, archive: boolean) {
  return await axios.patch(API.ARCHIVE(fileid), { archive });
}

/**
 * Archive all photos in a given list
 *
 * @param photos list of photos to process
 * @param archive Archive or unarchive
 * @returns list of file ids that were deleted
 */
export async function* archiveFilesByIds(photos: IPhoto[], archive: boolean) {
  if (!photos.length) return;

  // Add stack files
  photos = await base.extendWithStack(photos);

  // Archive each file
  const calls = photos.map((photo) => async () => {
    try {
      await archiveFile(photo.fileid, archive);
      return photo.fileid;
    } catch (error) {
      console.error('Failed to (un)archive', photo.fileid, error);
      const msg = error?.response?.data?.message || t('memories', 'General Failure');
      showError(t('memories', 'Error: {msg}', { msg }));
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
