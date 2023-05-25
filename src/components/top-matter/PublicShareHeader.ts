import { subscribe } from '@nextcloud/event-bus';
import { loadState } from '@nextcloud/initial-state';

// Shown in dynamic top matter (Timeline::viewName)
export const title = loadState('memories', 'share_title', '');

// Set up hook to monitor recycler scroll to show/hide header
if (title) {
  const header = document.querySelector('header#header .header-appname') as HTMLElement;
  let isHidden = false; // cache state to avoid unnecessary DOM updates

  // Hide header when recycler is scrolled down
  subscribe('memories.recycler.scroll', ({ current }: { current: number }) => {
    const hidden = current < 80;
    if (hidden === isHidden) return;
    header.classList.toggle('hidden', (isHidden = hidden));
  });
}
