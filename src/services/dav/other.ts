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
export function viewInFolderUrl({ filename, fileid }: { filename: string; fileid: number }) {
  // ensure dirPath starts with a slash
  let dirPath = dirname(filename);
  if (!dirPath.startsWith('/')) {
    dirPath = `/${dirPath}`;
  }

  return generateUrl(`/apps/files/?dir={dirPath}&scrollto={fileid}&openfile={fileid}`, {
    fileid,
    dirPath,
  });
}
