import { loadState } from '@nextcloud/initial-state';

// Shown in dynamic top matter (Timeline::viewName)
export const title = loadState('memories', 'share_title', '');
