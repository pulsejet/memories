import { translate as t, translatePlural as n } from '@nextcloud/l10n';

// https://github.com/nextcloud/server/blob/4b7ec0a0c18d4e2007565dc28ee214814940161e/core/src/OC/dialogs.js
const oc_dialogs = (<any>OC).dialogs;

type ConfirmOptions = {
  /** Title of dialog */
  title?: string;
  /** Message to display */
  message?: string;
  /** Type of dialog (default YES_NO_BUTTONS) */
  type?: string;
  /** Text for confirm button (default "Yes") */
  confirm?: string;
  /** Classes to add to confirm button */
  confirmClasses?: 'error' | 'primary';
  /** Text for cancel button (default "No") */
  cancel?: string;
  /** Whether to show a modal dialog (default true) */
  modal?: boolean;
};

export function confirmDestructive(options: ConfirmOptions): Promise<boolean> {
  const opts: ConfirmOptions = Object.assign(
    {
      title: '',
      message: '',
      type: oc_dialogs.YES_NO_BUTTONS,
      confirm: t('memories', 'Yes'),
      confirmClasses: 'error',
      cancel: t('memories', 'No'),
    },
    options ?? {}
  );

  // Observer to focus the confirm button when the dialog is shown
  let observer: MutationObserver;

  // In case the dialog did not show for whatever reason, cancel after 5 seconds
  const timeout = setTimeout(() => observer?.disconnect(), 5000);

  // Look for new dialog to be created
  observer = new MutationObserver((mutations) => {
    mutations.forEach((mutationRecord) => {
      mutationRecord.addedNodes.forEach((node) => {
        if (node instanceof HTMLDivElement && node.classList.contains('oc-dialog')) {
          (node.querySelector(`button.${opts.confirmClasses}`) as HTMLElement)?.focus?.();
          observer.disconnect();
          clearTimeout(timeout);
        }
      });
    });
  });

  // Watch changes to body
  observer.observe(document.body, { childList: true });

  return new Promise((resolve) => oc_dialogs.confirmDestructive(opts.message, opts.title, opts, resolve));
}

type PromptOptions = {
  /** Title of dialog */
  title?: string;
  /** Message to display */
  message?: string;
  /** Name of the input field */
  name?: string;
  /** Whether the input should be a password input */
  password?: boolean;
  /** Whether to show a modal dialog (default true) */
  modal?: boolean;
};

export async function prompt(opts: PromptOptions): Promise<string | null> {
  return new Promise((resolve) => {
    oc_dialogs.prompt(
      opts.message ?? '',
      opts.title ?? '',
      (success: boolean, value: string) => resolve(success ? value : null),
      opts.modal,
      opts.name,
      opts.password
    );
  });
}

/** Bespoke confirmation dialogs for re-use */
export const dialogs = {
  moveToTrash: (count: number) =>
    confirmDestructive({
      title: n('memories', 'Move {count} item to trash?', 'Move {count} items to trash?', count, { count }),
      message: t('memories', 'Files in trash may be automatically deleted after a fixed period of time.'),
    }),

  removeFromAlbum: (count: number) =>
    confirmDestructive({
      title: n('memories', 'Remove {count} item from album?', 'Remove {count} items from album?', count, {
        count,
      }),
      message: t('memories', 'This will not delete your original files.'),
    }),

  downloadItems: (count: number) =>
    confirmDestructive({
      title: t('memories', 'Download'),
      message: t('memories', 'You are about to download {count} items.', { count }),
      confirm: t('memories', 'Continue'),
      cancel: t('memories', 'Cancel'),
    }),

  moveItems: (count: number) =>
    confirmDestructive({
      title: t('memories', 'Move'),
      message: t('memories', 'You are about to move {count} items.', { count }),
      confirm: t('memories', 'Continue'),
      cancel: t('memories', 'Cancel'),
    }),
};
