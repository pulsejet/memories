import { loadState } from '@nextcloud/initial-state';
import type { IPhoto } from '@typings';

/**
 * Initial state pulled from Nextcloud's HTML page
 */
const initstate = Object.freeze({
  noDownload: loadState('memories', 'no_download', false) !== false,
  shareTitle: loadState('memories', 'share_title', '') as string,
  shareType: loadState('memories', 'share_type', null) as 'file' | 'folder' | 'album' | null,
  singleItem: loadState('memories', 'single_item', null) as IPhoto | null,
  allow_upload: loadState('memories', 'allow_upload', false) as boolean,
  allow_delete: loadState('memories', 'allow_delete', false) as boolean,
});

export default initstate;
