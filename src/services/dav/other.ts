import { getFiles } from './base';
import { generateUrl } from '@nextcloud/router';
import { IPhoto } from '../../types';
import { dirname } from 'path';

/**
 * Open the files app with the given photo
 * Opens a new window.
 */
export async function viewInFolder(photo: IPhoto) {
  const f = await getFiles([photo]);
  if (f.length === 0) return;

  const file = f[0];
  const url = generateUrl(`/apps/files/?dir={dirPath}&scrollto={fileid}&openfile={fileid}`, {
    dirPath: dirname(file.filename),
    fileid: file.fileid,
  });
  window.open(url, '_blank');
}
