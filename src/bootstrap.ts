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

// Types
import type { Route } from 'vue-router';
import type { IPhoto, IRow } from './types';
import type PlyrType from 'plyr';
import type videojsType from 'video.js';

// CSS for components
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';

// Global CSS
import './styles/global.scss';

// Global exposed variables
declare global {
  var mode: 'admin' | 'user';

  var vueroute: () => Route;
  var OC: Nextcloud.v24.OC;
  var OCP: Nextcloud.v24.OCP;

  var editMetadata: (photos: IPhoto[], sections?: number[]) => void;
  var updateAlbums: (photos: IPhoto[]) => void;
  var sharePhoto: (photo: IPhoto) => void;
  var shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;
  var showSettings: () => void;

  var mSidebar: {
    open: (photo: IPhoto | number, filename?: string, forceNative?: boolean) => void;
    close: () => void;
    setTab: (tab: string) => void;
    getWidth: () => number;
  };

  var mViewer: {
    open: (anchorPhoto: IPhoto, rows: IRow[]) => Promise<void>;
    openStatic(photo: IPhoto, list: IPhoto[], thumbSize?: 256 | 512): Promise<void>;
    close: () => void;
    isOpen: () => boolean;
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

// Turn on virtual keyboard support
if ('virtualKeyboard' in navigator) {
  (<any>navigator.virtualKeyboard).overlaysContent = true;
}

Vue.mixin(GlobalMixin as any);
Vue.use(VueVirtualScroller);
Vue.component('XImg', XImg);
Vue.component('XLoadingIcon', XLoadingIcon);
