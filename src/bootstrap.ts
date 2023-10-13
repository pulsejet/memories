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
  var __webpack_nonce__: string;
  var __webpack_public_path__: string;

  var OC: Nextcloud.Common.OC;
  var OCP: Nextcloud.Common.OCP;
  var OCA: {
    Files?: {
      Sidebar?: any;
      App?: any;
    };
    Theming?: {
      name: string;
      enabledThemes: any[];
    };
  };

  var _m: {
    mode: 'admin' | 'user';
    route: Route;

    modals: {
      editMetadata: (photos: IPhoto[], sections?: number[]) => void;
      updateAlbums: (photos: IPhoto[]) => void;
      sharePhoto: (photo: IPhoto) => void;
      shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;
      moveToFolder: (photos: IPhoto[]) => void;
      moveToFace: (photos: IPhoto[]) => void;
      showSettings: () => void;
    };

    sidebar: {
      open: (photo: IPhoto | number, filename?: string, forceNative?: boolean) => void;
      close: () => void;
      setTab: (tab: string) => void;
      getWidth: () => number;
    };

    viewer: {
      open: (anchorPhoto: IPhoto, rows: IRow[]) => Promise<void>;
      openStatic(photo: IPhoto, list: IPhoto[], thumbSize?: 256 | 512): Promise<void>;
      close: () => void;
      isOpen: boolean;
      currentPhoto: IPhoto | null;
    };

    video: {
      videojs: typeof videojsType;
      Plyr: typeof PlyrType;
      clientId: string;
      clientIdPersistent: string;
    };

    window: {
      innerWidth: number; // cache
      innerHeight: number; // cache
    };

    photoswipe?: unknown; // debugging only
  };
}

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
