// Library imports
import 'reflect-metadata';
import Vue from 'vue';

import { generateFilePath } from '@nextcloud/router';
import { getRequestToken } from '@nextcloud/auth';
import { translate, translatePlural } from '@nextcloud/l10n';

// Global components
import XImg from './components/frame/XImg.vue';
import XLoadingIcon from './components/XLoadingIcon.vue';
import VueVirtualScroller from 'vue-virtual-scroller';

// Locals
import router, { routes } from './router';
import { constants, initstate } from './services/utils';

// CSS for components
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';

// Global CSS
import './styles/global.scss';

// Initialize global memories object
globalThis._m = {
  mode: 'user',

  get route() {
    return router.currentRoute;
  },
  router: router,
  routes: routes,

  modals: {} as any,
  sidebar: {} as any,
  viewer: {} as any,
  video: {} as any,

  window: {
    innerWidth: window.innerWidth,
    innerHeight: window.innerHeight,
  },
};

// CSP config for webpack dynamic chunk loading
__webpack_nonce__ = window.btoa(getRequestToken() ?? '');

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// OC.generateUrl ensure the index.php (or not)
// We do not want the index.php since we're loading files
__webpack_public_path__ = generateFilePath('memories', '', 'js/');

// Generate client id for this instance
// Does not need to be cryptographically secure
_m.video.clientId = Math.random().toString(36).substring(2, 15).padEnd(12, '0');
_m.video.clientIdPersistent = localStorage.getItem('videoClientIdPersistent') ?? _m.video.clientId;
localStorage.setItem('videoClientIdPersistent', _m.video.clientIdPersistent);

// Turn on virtual keyboard support
if ('virtualKeyboard' in navigator) {
  (<any>navigator.virtualKeyboard).overlaysContent = true;
}

// Register global components and plugins
Vue.use(VueVirtualScroller);
Vue.component('XImg', XImg);
Vue.component('XLoadingIcon', XLoadingIcon);

// Register global constants and functions
Vue.prototype.c = constants;
Vue.prototype.initstate = initstate;
Vue.prototype.t = translate;
Vue.prototype.n = translatePlural;
