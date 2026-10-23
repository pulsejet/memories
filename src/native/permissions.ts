import { translate as t } from '@services/l10n';
import { confirmDestructive } from '@services/utils/dialog';

import { has, nativex } from './api';
import { configAllowManageMedia } from './config';

const ASKED_KEY = 'memories_manage_media_asked';

/**
 * Ask once about the media management grant for silent local delete.
 * A "no" is also remembered; the setup flow can trigger it again later.
 */
export async function maybeAskManageMedia(): Promise<void> {
  try {
    if (!has()) return;
    if (localStorage.getItem(ASKED_KEY)) return;
    if ((nativex?.canManageMedia?.() ?? null) !== false) return;
    const yes = await confirmDestructive({
      title: t('memories', 'Allow Memories to manage media'),
      message: t('memories', 'You can change this later in the device setup.'),
      confirm: t('memories', 'Continue'),
      confirmClasses: 'primary',
      cancel: t('memories', 'Not now'),
    });
    if (yes) await configAllowManageMedia();
    localStorage.setItem(ASKED_KEY, '1');
  } catch {
    // ignore: prompt again on next launch
  }
}
