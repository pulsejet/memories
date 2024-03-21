import { getFiles } from './base';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';

import type { ClusterTypes, IPhoto } from '@typings';

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

/**
 * Set the cluster cover image
 *
 * @param photo The photo to set as the cover
 */
export async function setClusterCover(photo: IPhoto): Promise<boolean> {
  if (!photo) return false;

  // Get cluster type from route name (fragile)
  const clusterType = _m.route.name as ClusterTypes;

  // Get other parameters
  const { fileid } = photo;
  let { user, name } = _m.route.params;

  if ([_m.routes.Recognize.name, _m.routes.Albums.name].includes(_m.route.name ?? String())) {
    name = `${user}/${name}`;
  }

  try {
    await axios.post(API.CLUSTER_SET_COVER(clusterType), { name, fileid });
    showSuccess(t('memories', 'Cover image set successfully'));
    return true;
  } catch (err) {
    showError(t('memories', 'Failed to set cover image'));
    console.error(err);
    return false;
  }
}
