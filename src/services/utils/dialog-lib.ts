/** Bundles @nextcloud/dialogs deps into a single lazy-loaded chunk. */
import '@nextcloud/dialogs/style.css';

export {
  getDialogBuilder,
  getFilePickerBuilder,
  showError,
  showInfo,
  showSuccess,
  showWarning,
} from '@nextcloud/dialogs';
