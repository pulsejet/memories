import { getFiles } from './base';
import { generateUrl } from '@nextcloud/router';
import { IPhoto } from '../../types';
import { dirname } from 'path';

/**
 * Open the files app with the given photo
 * Opens a new window.
 */
export async function viewInFolder(photo: IPhoto) {
  if (!photo) return;
  const files = await getFiles([photo]);
  if (files.length === 0) return;

  const url = viewInFolderUrl(files[0]);
  window.open(url, '_blank');
}

/**
 * Gets the view in folder url for the given file.
 */
export function viewInFolderUrl(file: { filename: string; fileid: number }) {
  return generateUrl(`/apps/files/?dir={dirPath}&scrollto={fileid}&openfile={fileid}`, {
    dirPath: dirname(file.filename),
    fileid: file.fileid,
  });
}
