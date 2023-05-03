import axios from '@nextcloud/axios';
import { showInfo } from '@nextcloud/dialogs';
import { API } from './API';
import { IConfig } from '../types';

class StaticConfig {
  private config: IConfig | null = null;
  private initPromises: Array<() => void> = [];
  private default: IConfig | null = null;

  public constructor() {
    this.init();
  }

  private async init() {
    const res = await axios.get<IConfig>(API.CONFIG_GET());
    this.config = res.data;

    // Check if version changed
    const old = this.getDefault();
    if (old.version !== this.config.version) {
      if (old.version) {
        showInfo('Memories has been updated. Please refresh to apply the changes.');
      }
      window.caches?.delete('pages');
    }

    // Assign to existing default
    for (const key in this.config) {
      this.default![key] = this.config[key];
      this.setLs(key as keyof IConfig, this.config[key]);
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

    localStorage.setItem(`memories_${key}`, value.toString());
  }

  public getDefault(): IConfig {
    if (this.default) {
      return this.default;
    }

    const config: IConfig = {
      version: '',
      vod_disable: false,
      video_default_quality: '0',
      places_gis: -1,

      systemtags_enabled: false,
      recognize_enabled: false,
      albums_enabled: false,
      facerecognition_installed: false,
      facerecognition_enabled: false,

      timeline_path: '',
      folders_path: '',
      show_hidden_folders: false,
      sort_folder_month: false,
      sort_album_month: true,
      enable_top_memories: true,

      square_thumbs: false,
      full_res_on_zoom: true,
      full_res_always: false,
      show_face_rect: false,
      album_list_sort: 1,
    };

    for (const key in config) {
      const val = localStorage.getItem(`memories_${key}`);
      if (val !== null) {
        if (typeof config[key] === 'boolean') {
          config[key] = val === 'true';
        } else if (typeof config[key] === 'number') {
          config[key] = Number(val);
        } else {
          config[key] = val;
        }
      }
    }

    this.default = config;

    return config;
  }
}

export default new StaticConfig();
