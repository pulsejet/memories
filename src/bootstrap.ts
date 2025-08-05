import { type App } from 'vue';

import { generateFilePath } from '@nextcloud/router';
import { getRequestToken } from '@nextcloud/auth';

// Global components
import XLoadingIcon from '@components/XLoadingIcon.vue';

// Locals
import { constants, initstate } from '@services/utils';
import { translate, translatePlural } from '@services/l10n';

// Global CSS
import './styles/global.scss';

// CSP config for webpack dynamic chunk loading
__webpack_nonce__ = window.btoa(getRequestToken() ?? '');

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// OC.generateUrl ensure the index.php (or not)
// We do not want the index.php since we're loading files
__webpack_public_path__ = generateFilePath('memories', '', 'js/');

// Turn on virtual keyboard support
if ('virtualKeyboard' in navigator) {
  (<any>navigator.virtualKeyboard).overlaysContent = true;
}

// Initialize Vue 3 app
export function bootstrapVueApp(app: App) {
  // Register global components and plugins
  app.component('XLoadingIcon', XLoadingIcon);

  // Register global constants and functions
  app.config.globalProperties.c = constants;
  app.config.globalProperties.initstate = initstate;
  app.config.globalProperties.t = translate;
  app.config.globalProperties.n = translatePlural;
}
