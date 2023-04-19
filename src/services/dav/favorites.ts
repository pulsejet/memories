import { showError } from '@nextcloud/dialogs';
import { translate as t } from '@nextcloud/l10n';
import { IFileInfo, IPhoto } from '../../types';
import client from '../DavClient';
import * as base from './base';
import * as utils from '../Utils';

/**
 * Favorite a file
 * https://github.com/nextcloud/photos/blob/4c3f62ceb0ef2a0007f4a8117d9655f1303fde6e/src/store/files.js
 *
 * @param fileName - The file's name
 * @param favoriteState - The new favorite state
 */
export function favoriteFile(fileName: string, favoriteState: boolean) {
  return client.customRequest(fileName, {
    method: 'PROPPATCH',
    data: `<?xml version="1.0"?>
            <d:propertyupdate xmlns:d="DAV:"
              xmlns:oc="http://owncloud.org/ns"
              xmlns:nc="http://nextcloud.org/ns"
              xmlns:ocs="http://open-collaboration-services.org/ns">
            <d:set>
              <d:prop>
                <oc:favorite>${favoriteState ? '1' : '0'}</oc:favorite>
              </d:prop>
            </d:set>
            </d:propertyupdate>`,
  });
}

/**
 * Favorite all files in a given list of photos
 *
 * @param photos list of photos
 * @param favoriteState the new favorite state
 * @returns generator of lists of file ids that were state-changed
 */
export async function* favoritePhotos(photos: IPhoto[], favoriteState: boolean) {
  if (photos.length === 0) {
    return;
  }

  // Get files data
  let fileInfos: IFileInfo[] = [];
  try {
    fileInfos = await base.getFiles(photos);
  } catch (e) {
    console.error('Failed to get file info', photos, e);
    showError(t('memories', 'Failed to favorite files.'));
    return;
  }

  if (fileInfos.length !== photos.length) {
    showError(t('memories', 'Failed to favorite some files.'));
  }

  // Favorite each file
  const calls = fileInfos.map((fileInfo) => async () => {
    try {
      await favoriteFile(fileInfo.originalFilename, favoriteState);
      const photo = photos.find((p) => p.fileid === fileInfo.fileid)!;
      if (favoriteState) {
        photo.flag |= utils.constants.c.FLAG_IS_FAVORITE;
      } else {
        photo.flag &= ~utils.constants.c.FLAG_IS_FAVORITE;
      }
      return fileInfo.fileid as number;
    } catch (error) {
      console.error('Failed to favorite', fileInfo, error);
      showError(
        t('memories', 'Failed to favorite {fileName}.', {
          fileName: fileInfo.originalFilename,
        })
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
