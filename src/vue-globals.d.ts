import { constants } from "./services/Utils";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";

declare module "vue" {
  interface ComponentCustomProperties {
    // GlobalMixin.ts
    t: typeof t;
    n: typeof n;

    c: typeof constants.c;
    TagDayID: typeof constants.TagDayID;
    TagDayIDValueSet: typeof constants.TagDayIDValueSet;

    state_noDownload: boolean;

    // UserConfig.ts
    config_timelinePath: string;
    config_foldersPath: string;
    config_showHidden: boolean;
    config_tagsEnabled: boolean;
    config_recognizeEnabled: boolean;
    config_facerecognitionInstalled: boolean;
    config_facerecognitionEnabled: boolean;
    config_albumsEnabled: boolean;
    config_placesGis: number;
    config_squareThumbs: boolean;
    config_enableTopMemories: boolean;
    config_showFaceRect: boolean;
    config_albumListSort: 1 | 2;
    config_eventName: string;

    updateSetting(setting: string): Promise<void>;
    updateLocalSetting({
      setting,
      value,
    }: {
      setting: string;
      value: any;
    }): void;
  }
}

export {};
