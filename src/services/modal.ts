import { ref, watch, type Ref } from 'vue';

import * as utils from '@services/utils/common';

/**
 * Shared logic for modal dialogs.
 *
 * @param modal ref of the inner Modal component, used to play the close animation
 */
export function useModal(modal?: Ref<{ close?: () => void } | null>) {
  const show = ref(false);
  let _closing: ((value: unknown) => void) | null = null;

  async function close() {
    if (show.value && !_closing) {
      // Claim the closing synchronously. Concurrent close() calls, e.g. from
      // fragment pop events emitted by our own pop() below, must be ignored.
      // Otherwise duplicate pop() calls race duplicate history navigations
      // against each other and the modal never closes.
      let resolveClosing!: (value: unknown) => void;
      const closing = new Promise<unknown>((resolve) => (resolveClosing = resolve));
      _closing = resolveClosing;

      try {
        // pop the fragment immediately
        await utils.fragment.pop(utils.fragment.types.modal);

        // close the modal with animation
        modal?.value?.close?.();

        // wait for transition to end (resolved by the show watcher)
        await closing;
      } catch (e) {
        // Never leave a stale claim behind: a failed close must stay retryable.
        if (_closing === resolveClosing) _closing = null;
        throw e;
      }
    }
  }

  utils.useBus('memories:fragment:pop:modal', close);

  watch(show, (value) => {
    utils.fragment.if(value, utils.fragment.types.modal);

    if (!value) {
      _closing?.(null);
      _closing = null;
    }
  });

  return { show, close };
}
