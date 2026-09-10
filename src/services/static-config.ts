import axios from '@nextcloud/axios';
import { showInfo, showError } from '@nextcloud/dialogs';
import { getBuilder } from '@nextcloud/browser-storage';
import { reactive } from 'vue';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { constants } from '@services/utils/const';
import { isNetworkError } from '@services/utils/helpers';
import { bus } from '@services/utils/event-bus';

import type { IConfig } from '@typings';

class StaticConfig {
  private config: IConfig | null = null;
  private default: IConfig;
  private storage;
  private verchange: boolean = false;
  private serverPromise: Promise<void>;

  public constructor() {
    this.storage = getBuilder('memories').clearOnLogout().persist().build();
    this.default = reactive(this.loadCached());
    this.serverPromise = this.fetchServer();
  }

  private async fetchServer() {
    let server: IConfig;
    try {
      server = (await axios.get<IConfig>(API.CONFIG_GET())).data;
    } catch (e) {
      if (!isNetworkError(e)) {
        showError('Failed to load configuration');
      }

      // Offline or fail, continue with cached configuration
      return;
    }

    // Snapshot of cached config for diffing
    const old = { ...this.default } as IConfig;

    // Check if version changed
    if (old.version !== server.version) {
      this.verchange = true;

      if (old.version) {
        showInfo(
          t('memories', 'Memories has been updated to {version}. Reload to get the new version.', {
            version: server.version,
          }),
        );
      }

      // Clear page cache, keep other caches
      window.caches?.delete('memories-pages');
    }

    // Copy over all missing settings (e.g. local settings)
    for (const key in old) {
      if (!server.hasOwnProperty(key)) {
        (server as any)[key] = (old as any)[key];
      }
    }

    this.config = server;

    // Update cached copy and storage, track changes
    let changed = false;
    for (const k in server) {
      const key = k as keyof IConfig;
      if (server[key] !== old[key]) {
        changed = true;
      }
      this.setLs(key, server[key]);
    }

    // Notify reactive consumers if server copy differs from cache
    if (changed) {
      bus.emit('memories:user-config-changed', null);
    }
  }

  public async getAll(): Promise<IConfig> {
    // Cached-first: do not block on server RTT.
    // Consumers are notified via bus event if server copy differs.
    return this.default;
  }

  public async get<K extends keyof IConfig>(key: K): Promise<IConfig[K]> {
    return this.default[key];
  }

  public getSync<K extends keyof IConfig>(key: K): IConfig[K] {
    return this.default[key];
  }

  public setLs<K extends keyof IConfig>(key: K, value: IConfig[K]) {
    this.default[key] = value;

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
    return this.default;
  }

  private loadCached(): IConfig {
    // get constants for easier access
    const { ALBUM_SORT_FLAGS } = constants;

    const config: IConfig = {
      // general stuff
      version: '',
      vod_disable: false,
      video_default_quality: '0',
      places_gis: -1,
      places_search_url: 'https://nominatim.openstreetmap.org',

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
      stack_raw_files: true,
      dedup_identical: false,
      show_owner_name_timeline: false,

      // viewer settings
      high_res_cond_default: 'zoom',
      livephoto_autoplay: true,
      livephoto_loop: false,
      video_loop: false,
      sidebar_filepath: false,
      metadata_in_slideshow: false,
      slideshow_duration: 5,

      // on this day settings
      onthisday_day_range: 3,
      onthisday_photos_per_year: 10,

      // folder settings
      folders_path: '',
      show_hidden_folders: false,
      sort_folder_month: false,

      // album settings
      sort_album_month: true,
      show_hidden_albums: false,
      album_list_sort: ALBUM_SORT_FLAGS.CREATED | ALBUM_SORT_FLAGS.DESCENDING, // also in OtherController.php

      // local settings
      square_thumbs: false,
      high_res_cond: null,
      show_face_rect: false,
    };

    const set = <K extends keyof IConfig, V extends IConfig[K]>(key: K, value: string | null) => {
      if (value == null) return;

      if (typeof config[key] === 'boolean') {
        config[key] = (value === 'true') as V;
      } else if (typeof config[key] === 'number') {
        const n = Number(value);
        if (Number.isFinite(n)) config[key] = n as V;
      } else {
        config[key] = value as V;
      }
    };

    for (const key in config) {
      set(key as keyof IConfig, this.storage.getItem(`memories_${key}`));
    }

    return config;
  }

  public async versionChanged(): Promise<boolean> {
    await this.serverPromise;
    return this.verchange;
  }
}

export default new StaticConfig();
