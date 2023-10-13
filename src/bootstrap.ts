// Library imports
import 'reflect-metadata';
import Vue from 'vue';
import { generateFilePath } from '@nextcloud/router';
import { getRequestToken } from '@nextcloud/auth';

// Global components
import XImg from './components/frame/XImg.vue';
import XLoadingIcon from './components/XLoadingIcon.vue';
import GlobalMixin from './mixins/GlobalMixin';
import VueVirtualScroller from 'vue-virtual-scroller';

// Locals
import router from './router';

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
const getClientId = (): string => Math.random().toString(36).substring(2, 15).padEnd(12, '0');
_m.video.clientId = getClientId();
_m.video.clientIdPersistent = localStorage.getItem('videoClientIdPersistent') ?? getClientId();
localStorage.setItem('videoClientIdPersistent', _m.video.clientIdPersistent);

// Turn on virtual keyboard support
if ('virtualKeyboard' in navigator) {
  (<any>navigator.virtualKeyboard).overlaysContent = true;
}

Vue.mixin(GlobalMixin as any);
Vue.use(VueVirtualScroller);
Vue.component('XImg', XImg);
Vue.component('XLoadingIcon', XLoadingIcon);
