import { loadState } from '@nextcloud/initial-state';
import * as utils from '../../services/utils';

// Shown in dynamic top matter (Timeline::viewName)
export const title = loadState('memories', 'share_title', '');

// Set up hook to monitor recycler scroll to show/hide header
if (title) {
  const header = document.querySelector('header#header .header-appname') as HTMLElement;
  let isHidden = false; // cache state to avoid unnecessary DOM updates

  // Hide header when recycler is scrolled down
  utils.bus.on('memories.recycler.scroll', ({ dynTopMatterVisible }: { dynTopMatterVisible: boolean }) => {
    if (dynTopMatterVisible === isHidden) return;
    header.classList.toggle('hidden', (isHidden = dynTopMatterVisible));
  });
}
