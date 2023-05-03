import 'reflect-metadata';
import Vue from 'vue';
import VueVirtualScroller from 'vue-virtual-scroller';
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';
import XImg from './components/frame/XImg.vue';
import XLoadingIcon from './components/XLoadingIcon.vue';
import GlobalMixin from './mixins/GlobalMixin';

import App from './App.vue';
import router from './router';
import { generateFilePath } from '@nextcloud/router';
import { getRequestToken } from '@nextcloud/auth';

import type { Route } from 'vue-router';
import type { IPhoto } from './types';
import type { NativeX } from './types-native';
import type PlyrType from 'plyr';
import type videojsType from 'video.js';

import './global.scss';

// Global exposed variables
declare global {
  var mode: 'admin' | 'user';

  var vueroute: () => Route;
  var OC: Nextcloud.v24.OC;
  var OCP: Nextcloud.v24.OCP;

  var editMetadata: (photos: IPhoto[], sections?: number[]) => void;
  var sharePhoto: (photo: IPhoto) => void;
  var shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;
  var showSettings: () => void;

  var mSidebar: {
    open: (fileid: number, filename?: string, forceNative?: boolean) => void;
    close: () => void;
    setTab: (tab: string) => void;
    getWidth: () => number;
  };

  var currentViewerPhoto: IPhoto;

  var windowInnerWidth: number; // cache
  var windowInnerHeight: number; // cache

  var __webpack_nonce__: string;
  var __webpack_public_path__: string;

  var vidjs: typeof videojsType;
  var Plyr: typeof PlyrType;
  var videoClientId: string;
  var videoClientIdPersistent: string;

  var nativex: NativeX | undefined;
}

// Allow global access to the router
globalThis.vueroute = () => router.currentRoute;

// Cache these for better performance
globalThis.windowInnerWidth = window.innerWidth;
globalThis.windowInnerHeight = window.innerHeight;

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
globalThis.videoClientId = getClientId();
globalThis.videoClientIdPersistent = localStorage.getItem('videoClientIdPersistent') ?? getClientId();
localStorage.setItem('videoClientIdPersistent', globalThis.videoClientIdPersistent);

Vue.mixin(GlobalMixin as any);
Vue.use(VueVirtualScroller);
Vue.component('XImg', XImg);
Vue.component('XLoadingIcon', XLoadingIcon);

let app = null;

const adminSection = document.getElementById('memories-admin-content');
if (adminSection) {
  import('./components/admin/AdminMain.vue').then((module) => {
    app = new Vue({
      el: '#memories-admin-content',
      render: (h) => h(module.default),
    });
  });
  globalThis.mode = 'admin';
} else {
  app = new Vue({
    el: '#content',
    router,
    render: (h) => h(App),
  });
  globalThis.mode = 'user';
}

export default app;
