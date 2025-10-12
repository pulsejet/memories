import type Router, { type Route } from 'vue-router';
import type { ComponentPublicInstance } from 'vue';

import type PlyrType from 'plyr';
import type videojsType from 'video.js';

import type { IPhoto, TimelineState } from '@typings';
import type { constants, initstate } from '@services/utils';
import type { translate, translatePlural } from '@services/l10n';
import type { GlobalRouteCheckers, routes } from './router';

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

  /**
   * Global Memories object. Initialized in main.ts
   * Most of this is not available for admin.ts.
   */
  var _m: {
    mode: 'admin' | 'user';
    route: Route;
    router: Router;
    routes: typeof routes;

    modals: {
      editMetadata: (photos: IPhoto[], sections?: number[]) => void;
      updateAlbums: (photos: IPhoto[]) => void;
      sharePhotos: (photo: IPhoto[]) => void;
      shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;
      moveToFolder: (photos: IPhoto[]) => void;
      moveToFace: (photos: IPhoto[]) => void;
      albumShare: (user: string, name: string, link?: boolean) => Promise<void>;
      showSettings: () => void;
      upload: () => void;
      search: () => void;
    };

    selectionManager: {
      selectPhoto: (photo: IPhoto, val?: boolean, noUpdate?: boolean) => void;
    };

    sidebar: {
      open: (photo: IPhoto | number, filename?: string, forceNative?: boolean) => void;
      close: () => void;
      isOpen: () => boolean;
      setTab: (tab: string) => void;
      invalidateUnless: (fileid: number) => void;
      getWidth: () => number;
    };

    viewer: {
      open: (photo: IPhoto) => void;
      openDynamic: (anchorPhoto: IPhoto, timeline: TimelineState) => Promise<void>;
      openStatic(photo: IPhoto, list: IPhoto[], thumbSize?: 256 | 512): Promise<void>;
      close: () => void;
      isOpen: boolean;
      currentPhoto: IPhoto | null;
      photoswipe?: unknown; // debugging only
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
  };

  // Typings for external libraries below
  type VueRecyclerType = ComponentPublicInstance & {
    $el: HTMLDivElement;
    $refs: {
      wrapper: HTMLDivElement;
    };
    scrollToPosition: (position: number) => void;
    scrollToItem: (index: number) => void;
  };

  type VueNcPopover = ComponentPublicInstance & {
    $refs: { popover: { show(): void; hide(): void } };
  };

  type VueNcSelectTags = ComponentPublicInstance & {
    availableTags: any[];
  };

  type VueHTMLComponent = ComponentPublicInstance & {
    $el: HTMLElement;
  };
}

// types present on all components (bootstrap.ts, router.ts)
declare module 'vue' {
  interface ComponentCustomProperties extends GlobalRouteCheckers {
    t: typeof translate;
    n: typeof translatePlural;

    c: typeof constants;
    initstate: typeof initstate;
  }

  export interface GlobalComponents {
    XLoadingIcon: typeof import('@components/XLoadingIcon.vue').default;
    XImg: typeof import('@components/frame/XImg.vue').default;
  }
}

export {};
