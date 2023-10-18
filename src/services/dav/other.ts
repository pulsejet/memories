import { getFiles } from './base';
import { generateUrl } from '@nextcloud/router';
import { IPhoto } from '../../types';
import { API } from '../API';

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
  let dirPath = filename.substring(0, filename.lastIndexOf('/'));
  if (!dirPath.startsWith('/')) {
    dirPath = `/${dirPath}`;
  }

  /** @todo Doesn't seem to work on Nextcloud 28 */
  return API.Q(generateUrl('/apps/files/'), {
    dir: dirPath,
    scrollto: fileid,
    openfile: fileid,
  });
}
