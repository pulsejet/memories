import { type constants } from './services/utils';
import type { translate, translatePlural } from '@nextcloud/l10n';

declare module 'vue' {
  interface ComponentCustomProperties {
    // GlobalMixin.ts
    t: typeof translate;
    n: typeof translatePlural;

    c: typeof constants.c;

    state_noDownload: boolean;

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
