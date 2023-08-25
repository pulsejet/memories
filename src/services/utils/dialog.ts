import { translate as t } from '@nextcloud/l10n';

// https://github.com/nextcloud/server/blob/4b7ec0a0c18d4e2007565dc28ee214814940161e/core/src/OC/dialogs.js
const dialogs = (<any>OC).dialogs;

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
      type: dialogs.YES_NO_BUTTONS,
      confirm: t('memories', 'Yes'),
      confirmClasses: 'error',
      cancel: t('memories', 'No'),
    },
    options ?? {}
  );

  return new Promise((resolve) => dialogs.confirmDestructive(opts.message, opts.title, opts, resolve));
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
    dialogs.prompt(
      opts.message ?? '',
      opts.title ?? '',
      (success: boolean, value: string) => resolve(success ? value : null),
      opts.modal,
      opts.name,
      opts.password
    );
  });
}
