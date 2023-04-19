import { type constants } from "./services/Utils";
import type { translate, translatePlural } from "@nextcloud/l10n";

declare module "vue" {
  interface ComponentCustomProperties {
    // GlobalMixin.ts
    t: typeof translate;
    n: typeof translatePlural;

    c: typeof constants.c;

    state_noDownload: boolean;

    // UserConfig.ts
    config_timelinePath: string;
    config_foldersPath: string;
    config_showHidden: boolean;
    config_sortFolderMonth: boolean;
    config_sortAlbumMonth: boolean;
    config_tagsEnabled: boolean;
    config_recognizeEnabled: boolean;
    config_facerecognitionInstalled: boolean;
    config_facerecognitionEnabled: boolean;
    config_albumsEnabled: boolean;
    config_placesGis: number;
    config_squareThumbs: boolean;
    config_enableTopMemories: boolean;
    config_fullResOnZoom: boolean;
    config_fullResAlways: boolean;
    config_showFaceRect: boolean;
    config_albumListSort: 1 | 2;
    config_eventName: string;

    updateSetting: (setting: string) => Promise<void>;
    updateLocalSetting: (opts: { setting: string; value: any }) => void;
  }
}

export {};
