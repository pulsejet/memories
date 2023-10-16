import type { Route } from 'vue-router';
import type { ComponentPublicInstance } from 'vue';

import type { translate, translatePlural } from '@nextcloud/l10n';

import type PlyrType from 'plyr';
import type videojsType from 'video.js';

import type { IPhoto, IRow } from './types';
import type { c, initState } from './services/utils';

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

  /** Global Memories object. Initialized in src/bootstrap.ts  */
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

// GlobalMixin.ts types, present on all components
declare module 'vue' {
  interface ComponentCustomProperties {
    t: typeof translate;
    n: typeof translatePlural;

    c: typeof c;
    initState: typeof initState;

    routeIsBase: boolean;
    routeIsFavorites: boolean;
    routeIsVideos: boolean;
    routeIsFolders: boolean;
    routeIsAlbums: boolean;
    routeIsPeople: boolean;
    routeIsRecognize: boolean;
    routeIsRecognizeUnassigned: boolean;
    routeIsFaceRecognition: boolean;
    routeIsArchive: boolean;
    routeIsPlaces: boolean;
    routeIsMap: boolean;
    routeIsTags: boolean;
    routeIsExplore: boolean;
    routeIsAlbumShare: boolean;
    routeIsPublic: boolean;
  }
}

export {};
