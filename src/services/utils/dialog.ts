import { getDialogBuilder, getFilePickerBuilder, showError } from '@nextcloud/dialogs';
import { spawnDialog } from '@nextcloud/vue/functions/dialog';

import PromptDialog from '@components/modal/PromptDialog.vue';

import { translatePlural as n, translate as t } from '@services/l10n';
import { bus } from './event-bus';
import { fragment } from './fragment';

import type { INode } from '@nextcloud/files';
import type { IFilePickerButton } from '@nextcloud/dialogs';

type ConfirmOptions = {
  /** Title of dialog */
  title?: string;
  /** Message to display */
  message?: string;
  /** Text for confirm button (default "Yes") */
  confirm?: string;
  /** Classes to add to confirm button */
  confirmClasses?: 'error' | 'primary';
  /** Text for cancel button (default "No") */
  cancel?: string;
};

// Register fragment navigation
bus.on('memories:fragment:pop:dialog', () => {
  const button = document.querySelector('[role="dialog"]:last-of-type button.modal-container__close') as HTMLElement;
  if (!button?.click) return;

  // Some dialogs are simply modals, so we need to make sure that
  // we don't close the underlying modal when closing the dialog.
  // This happens if the dialog was actually closed by a button,
  // and the route was subsequently popped by the fragment service.
  if (button.closest('.memories-modal')) return;

  // Close the dialog
  button.click();
});

export function confirmDestructive(options: ConfirmOptions): Promise<boolean> {
  const opts: ConfirmOptions = Object.assign(
    {
      title: '',
      message: '',
      confirm: t('memories', 'Yes'),
      confirmClasses: 'error',
      cancel: t('memories', 'No'),
    },
    options ?? {},
  );

  let result = false;
  const dialog = getDialogBuilder(opts.title ?? '')
    .setText(opts.message ?? '')
    .setSeverity('error')
    .setButtons([
      {
        label: opts.cancel ?? t('memories', 'No'),
        callback: () => {},
      },
      {
        label: opts.confirm ?? t('memories', 'Yes'),
        variant: opts.confirmClasses,
        callback: () => {
          result = true;
        },
      },
    ])
    .build();

  // Dialog.show() rejects when the dialog is closed without a button press
  const promise = dialog.show().then(
    () => result,
    () => false,
  );

  return fragment.wrap(promise, fragment.types.dialog);
}

type PromptOptions = {
  /** Title of dialog */
  title?: string;
  /** Message to display */
  message?: string;
  /** Label of the input field */
  name?: string;
  /** Whether the input should be a password input */
  password?: boolean;
};

export function prompt(opts: PromptOptions): Promise<string | null> {
  return fragment.wrap(
    spawnDialog(PromptDialog, {
      title: opts.title ?? '',
      message: opts.message ?? '',
      label: opts.name ?? '',
      password: opts.password ?? false,
    }),
    fragment.types.dialog,
  );
}

/** Default button factory for the file picker */
function chooseButtonFactory(nodes: INode[]): IFilePickerButton[] {
  const fileName = nodes?.[0]?.attributes?.displayName || nodes?.[0]?.basename;
  let label = nodes.length === 1 ? t('memories', 'Choose {file}', { file: fileName }) : t('memories', 'Choose');
  return [
    {
      callback: () => {},
      variant: 'primary',
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
