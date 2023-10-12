import axios from '@nextcloud/axios';
import { showInfo, showError } from '@nextcloud/dialogs';
import { API } from './API';
import { IConfig } from '../types';
import { getBuilder } from '@nextcloud/browser-storage';
import { translate as t } from '@nextcloud/l10n';
import * as utils from './utils';

import type Storage from '@nextcloud/browser-storage/dist/storage';

class StaticConfig {
  private config: IConfig | null = null;
  private initPromises: Array<() => void> = [];
  private default: IConfig | null = null;
  private storage: Storage;

  public constructor() {
    this.storage = getBuilder('memories').clearOnLogout().persist().build();
    this.init();
  }

  private async init() {
    try {
      const res = await axios.get<IConfig>(API.CONFIG_GET());
      this.config = res.data as IConfig;
    } catch (e) {
      if (!utils.isNetworkError(e)) {
        showError('Failed to load configuration');
      }

      // Offline or fail, continue with default configuration
      this.config = this.getDefault();
    }

    // Check if version changed
    const old = this.getDefault();
    if (old.version !== this.config.version) {
      if (old.version) {
        showInfo(
          t('memories', 'Memories has been updated to {version}. Reload to get the new version.', {
            version: this.config.version,
          })
        );
      }

      // Clear page cache, keep other caches
      window.caches?.delete('memories-pages');
    }

    // Assign to existing default
    for (const k in this.config) {
      const key = k as keyof IConfig;
      this.setLs(key, this.config[key]);
    }

    // Resolve all promises
    this.initPromises.forEach((resolve) => resolve());
  }

  private async waitForInit() {
    if (!this.config) {
      await new Promise<void>((resolve) => {
        this.initPromises.push(resolve);
      });
    }
  }

  public async getAll() {
    await this.waitForInit();
    return this.config!;
  }

  public async get<K extends keyof IConfig>(key: K) {
    await this.waitForInit();
    return this.config![key];
  }

  public getSync<K extends keyof IConfig>(key: K) {
    return this.getDefault()[key];
  }

  public setLs<K extends keyof IConfig>(key: K, value: IConfig[K]) {
    if (this.default) {
      this.default[key] = value;
    }

    if (this.config) {
      this.config[key] = value;
    }

    if (value == null) {
      this.storage.removeItem(`memories_${key}`);
      return;
    }

    this.storage.setItem(`memories_${key}`, value.toString());
  }

  public getDefault(): IConfig {
    if (this.default) {
      return this.default;
    }

    const config: IConfig = {
      // general stuff
      version: '',
      vod_disable: false,
      video_default_quality: '0',
      places_gis: -1,

      // enabled apps
      systemtags_enabled: false,
      albums_enabled: false,
      recognize_installed: false,
      recognize_enabled: false,
      facerecognition_installed: false,
      facerecognition_enabled: false,
      preview_generator_enabled: false,

      // general settings
      timeline_path: '_unknown_',
      enable_top_memories: true,

      // viewer settings
      high_res_cond_default: 'zoom',
      livephoto_autoplay: true,
      sidebar_filepath: false,

      // folder settings
      folders_path: '',
      show_hidden_folders: false,
      sort_folder_month: false,

      // album settings
      sort_album_month: true,

      // local settings
      square_thumbs: false,
      high_res_cond: null,
      show_face_rect: false,
      album_list_sort: 1,
    };

    const set = <K extends keyof IConfig, V extends IConfig[K]>(key: K, value: string | null) => {
      if (value == null) return;

      if (typeof config[key] === 'boolean') {
        config[key] = (value === 'true') as V;
      } else if (typeof config[key] === 'number') {
        config[key] = Number(value) as V;
      } else {
        config[key] = value as V;
      }
    };

    for (const key in config) {
      set(key as keyof IConfig, this.storage.getItem(`memories_${key}`));
    }

    this.default = config;

    return config;
  }
}

export default new StaticConfig();
