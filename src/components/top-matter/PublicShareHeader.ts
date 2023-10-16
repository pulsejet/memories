import * as utils from '../../services/utils';

// Shown in dynamic top matter (Timeline::viewName)
export const title = utils.initstate.shareTitle;

// Set up hook to monitor recycler scroll to show/hide header
if (title) {
  const header = document.querySelector('header#header .header-appname') as HTMLElement;
  let isHidden = false; // cache state to avoid unnecessary DOM updates

  // Hide header when recycler is scrolled down
  utils.bus.on('memories.recycler.scroll', ({ dynTopMatterVisible }) => {
    if (dynTopMatterVisible === isHidden) return;
    header.classList.toggle('hidden', (isHidden = dynTopMatterVisible));
  });
}
