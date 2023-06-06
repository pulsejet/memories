import * as base from './base';
import { showError } from '@nextcloud/dialogs';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { API } from '../API';

/**
 * Archive or unarchive a single file
 *
 * @param fileid File id
 * @param archive Archive or unarchive
 */
export async function archiveFile(fileIds: number[], archive: boolean) {
  return await axios.patch(API.ARCHIVE(), { archive, fileIds });
}

/**
 * Archive all files in a given list of Ids
 *
 * @param fileIds list of file ids
 * @param archive Archive or unarchive
 * @returns list of file ids that were deleted
 */
export async function* archiveFilesByIds(fileIds: number[], archive: boolean) {
  if (fileIds.length === 0) {
    return;
  }
  // Archive each file
  try {
    await archiveFile(fileIds, archive);
    yield fileIds;
  } catch (error) {
    console.error('Failed to (un)archive', fileIds, error);
    const msg = error?.response?.data?.message || t('memories', 'General Failure');
    showError(t('memories', 'Error: {msg}', { msg }));
    return 0;
  }
}
