import * as base from './base';
import { showError } from '@nextcloud/dialogs';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { API } from '../API';
import { createClient } from 'webdav'
import { generateRemoteUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
const client = createClient(generateRemoteUrl('dav'))


/**
 * Archive or unarchive a single file
 *
 * @param fileid File id
 * @param archive Archive or unarchive
 */
export async function archiveFile(fileid: number, archive: boolean) {
  const result = await axios.post(API.ARCHIVE(fileid), { archive });
  var folderData = result.data;
  var folder = '';
  for (let index = 0; index < folderData.destinationFolders.length; index++) {
    folder += '/' + folderData.destinationFolders[index];
    const folderExists = await client.exists(`/files/${getCurrentUser()?.uid}${folder}`)
    if(!folderExists){
      try {
        await client.createDirectory(`/files/${getCurrentUser()?.uid}${folder}`);
      } catch (error) {
        
        console.error(error);
        showError(t('photos', 'Failed to create album'));
      }
    }     
  }
  const response = await client.moveFile(`/files/${getCurrentUser()?.uid}/${folderData.folderPath}`, `/files/${getCurrentUser()?.uid}/${folderData.destinationPath}`) 
  return fileid;
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
  const calls = fileIds.map((id) => async () => {
    try {
      await archiveFile(id, archive);
      return id as number;
    } catch (error) {
      console.error('Failed to (un)archive', id, error);
      const msg = error?.response?.data?.message || t('memories', 'General Failure');
      showError(t('memories', 'Error: {msg}', { msg }));
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
