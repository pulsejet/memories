import Vue from 'vue';

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

// Register global components and plugins
Vue.component('XLoadingIcon', XLoadingIcon);

// Register global constants and functions
Vue.prototype.c = constants;
Vue.prototype.initstate = initstate;
Vue.prototype.t = translate;
Vue.prototype.n = translatePlural;
