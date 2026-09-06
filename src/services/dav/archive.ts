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
  const extended = await base.extendWithStack(photos);
  const livePhotoVideoFileIds = extended.livePhotoVideoFileIds;
  photos = extended.photos;

  /** Archive server file */
  const _archive_file = async (photo: IPhoto, silenceErrors: boolean = false) => {
    try {
      await archiveFile(photo.fileid, archive);
      return photo.fileid;
    } catch (error) {
      if (silenceErrors) return 0;
      console.error('Failed to (un)archive', photo.fileid, error);
      const msg = error?.response?.data?.message || t('memories', 'General Failure');
      showError(t('memories', 'Error: {msg}', { msg }));
      return 0;
    }
  };

  const regularPhotos = photos.filter((photo) => !livePhotoVideoFileIds.has(photo.fileid));
  const livePhotoVideoPhotos = photos.filter((photo) => livePhotoVideoFileIds.has(photo.fileid));

  const calls = regularPhotos.map((photo) => () => _archive_file(photo));
  yield* base.runInParallel(calls, 10);
  const livePhotoVideoCalls = livePhotoVideoPhotos.map((photo) => () => _archive_file(photo, true));
  for await (const _ of base.runInParallel(livePhotoVideoCalls, 10)) {
    // ignore results for live photo video operations
  }
}
