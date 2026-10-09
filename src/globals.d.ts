import type { Router, RouteLocationNormalized } from 'vue-router';
import type { ComponentPublicInstance } from 'vue';

import type { IPhoto, IUploadNativeX, TimelineState } from '@typings';
import type { routes } from '@services/router';

// Global exposed variables
declare global {
  var __webpack_nonce__: string;
  var __webpack_public_path__: string;
  var __packed_l10n: Record<string, unknown> | undefined;

  var OC: Nextcloud.Common.OC;
  var OCP: Nextcloud.Common.OCP;
  var OCA: {
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
    route: RouteLocationNormalized;
    router: Router;
    routes: typeof routes;

    modals: {
      editMetadata: (photos: IPhoto[], sections?: number[]) => void;
      updateAlbums: (photos: IPhoto[]) => void;
      sharePhotos: (photo: IPhoto[]) => void;
      shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;
      moveToFolder: (photos: IPhoto[]) => void;
      moveToFace: (photos: IPhoto[]) => void;
      reindex: (photos: IPhoto[]) => void;
      albumShare: (user: string, name: string, link?: boolean) => Promise<void>;
      showSettings: () => void;
      upload: (locals?: IUploadNativeX[]) => void;
      search: () => void;
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
      clientId: string;
      clientIdPersistent: string;
    };
  };

  // Typings for external libraries below
  type VueRecyclerType = Omit<ComponentPublicInstance, '$el'> & {
    $el: HTMLDivElement;
    scrollToPosition: (position: number) => void;
    scrollToItem: (index: number) => void;
    findItemIndex: (offset: number) => number;
  };

  type VueHTMLComponent = Omit<ComponentPublicInstance, '$el'> & {
    $el: HTMLElement;
  };
}

export {};
