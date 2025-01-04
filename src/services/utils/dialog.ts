import { getFilePickerBuilder, showError } from '@nextcloud/dialogs';

import { translatePlural as n, translate as t } from '@services/l10n';
import { bus } from './event-bus';
import { fragment } from './fragment';

import type { Node } from '@nextcloud/files';
import type { IFilePickerButton } from '@nextcloud/dialogs';

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

// Register fragment navigation
bus.on('memories:fragment:pop:dialog', () => {
  const selectors = ['button.oc-dialog-close', '[role="dialog"]:last-of-type button.modal-container__close'].join(', ');
  const button = document.querySelector(selectors) as HTMLElement;
  if (!button?.click) return;

  // Some dialogs are simply modals, so we need to make sure that
  // we don't close the underlying modal when closing the dialog.
  // This happens if the dialog was actually closed by a button,
  // and the route was subsequently popped by the fragment service.
  if (button.closest('.memories-modal')) return;

  // Close the dialog
  button.click();
});

/**
 * Wait for a dialog to be created with a timeout.
 *
 * @param callback Callback to run when the dialog is created
 */
function waitForDialog(callback: (dialog: HTMLDivElement) => void) {
  // Callback when dialog is created for initializations
  const onCreate = (dialog: HTMLDivElement) => {
    const closeButton = dialog.querySelector<HTMLButtonElement>('button.oc-dialog-close');

    // Handle keyboard actions
    dialog.addEventListener('keydown', (e) => {
      // Trap keydown events inside the dialog
      e.stopPropagation();

      // Override the default behavior of the escape key
      if (e.key === 'Escape') {
        e.stopImmediatePropagation();
        closeButton?.click();
      }
    });

    // Run the callback
    callback(dialog);
  };

  // Look for new dialog to be created with a 5s timeout
  let observer: MutationObserver;
  const timeout = setTimeout(() => observer?.disconnect(), 5000);

  // Observer for new dialogs
  observer = new MutationObserver((mutations) =>
    mutations.forEach((record) => {
      record.addedNodes.forEach((node) => {
        if (node instanceof HTMLDivElement && node.classList.contains('oc-dialog')) {
          observer.disconnect();
          clearTimeout(timeout);
          onCreate(node);
        }
      });
    }),
  );

  // Watch changes to body
  observer.observe(document.body, { childList: true });
}

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
    options ?? {},
  );

  waitForDialog((dialog) => {
    // Focus the confirm button
    dialog.querySelector<HTMLButtonElement>(`button.${opts.confirmClasses}`)?.focus();
  });

  return fragment.wrap(
    new Promise((resolve) => oc_dialogs.confirmDestructive(opts.message, opts.title, opts, resolve)),
    fragment.types.dialog,
  );
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
  waitForDialog((dialog) => {
    // Add class for patch.scss
    dialog.classList.add('dialog-prompt');

    // Focus the input field
    dialog.querySelector<HTMLInputElement>('input[type="text"]')?.focus();
  });

  return fragment.wrap(
    new Promise((resolve) =>
      oc_dialogs.prompt(
        opts.message ?? '',
        opts.title ?? '',
        (success: boolean, value: string) => resolve(success ? value : null),
        opts.modal,
        opts.name,
        opts.password,
      ),
    ),
    fragment.types.dialog,
  );
}

/** Default button factory for the file picker */
function chooseButtonFactory(nodes: Node[]): IFilePickerButton[] {
  const fileName = nodes?.[0]?.attributes?.displayName || nodes?.[0]?.basename;
  let label = nodes.length === 1 ? t('memories', 'Choose {file}', { file: fileName }) : t('memories', 'Choose');
  return [
    {
      callback: () => {},
      type: 'primary',
      label: label,
    },
  ];
}

/**
 * Choose a folder using the NC file picker
 *
 * @param title Title of the file picker
 * @param initial Initial path
 * @param buttonFactory Buttons factory
 *
 * @returns The path of the chosen folder
 */
export async function chooseNcFolder(
  title: string,
  initial: string = '/',
  buttonFactory = chooseButtonFactory,
): Promise<string> {
  const picker = getFilePickerBuilder(title)
    .setMultiSelect(false)
    .setButtonFactory(buttonFactory)
    .addMimeTypeFilter('httpd/unix-directory')
    .allowDirectories()
    .startAt(initial)
    .build();

  // Choose a folder
  let folder = await fragment.wrap(picker.pick(), fragment.types.dialog);
  if (typeof folder !== 'string') {
    throw new Error('File picker did not return a string');
  }

  // Blank is not a valid folder
  folder = folder || '/';

  // Remove double slashes
  folder = folder.replace(/\/+/g, '/');

  // Look for any trailing or leading whitespace
  if (folder.trim() !== folder) {
    showError(
      t(
        'memories',
        'The folder name "{folder}" has a leading or trailing whitespace. This may lead to errors and should be corrected.',
        { folder },
      ),
    );
  }

  return folder;
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
