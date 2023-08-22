import { translate as t } from '@nextcloud/l10n';

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
  const opts = Object.assign(
    {
      title: '',
      message: '',
      type: (<any>OC.dialogs).YES_NO_BUTTONS,
      confirm: t('memories', 'Yes'),
      confirmClasses: 'error',
      cancel: t('memories', 'No'),
    },
    options ?? {}
  );

  const { title, message } = opts;

  return new Promise((resolve) => (<any>OC.dialogs).confirmDestructive(message, title, opts, resolve));
}
