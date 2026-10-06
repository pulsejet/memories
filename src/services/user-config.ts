import { reactive, readonly, type DeepReadonly } from 'vue';

import axios from '@nextcloud/axios';
import { showInfo, showError } from '@nextcloud/dialogs';
import { getBuilder } from '@nextcloud/browser-storage';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { constants } from '@services/utils/const';
import { isNetworkError } from '@services/utils/helpers';
import { nativex } from '@native/api';

import type { IConfig } from '@typings';

/** Settings stored locally only, never sent to the server. */
const localSettings: (keyof IConfig)[] = ['square_thumbs', 'high_res_cond', 'show_face_rect'];

class UserConfig {
  private currentConfig: IConfig;
  private storage;
  private serverPromise: Promise<void>;
  private versionChanged: boolean = false;

  public constructor() {
    this.storage = getBuilder('memories').clearOnLogout().persist().build();
    this.currentConfig = reactive(this.loadCached());
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
    const old = { ...this.currentConfig } as IConfig;

    // Check if version changed
    if (old.version !== server.version) {
      this.versionChanged = true;

      // Let the user know they might need a page refresh to get a new version.
      // None of the callers know about the old version, so we need to do this here.
      if (!nativex && old.version) {
        this.notifyVersionChanged(server.version);
      }

      // Clear page cache, keep other caches
      window.caches?.delete('memories-pages');
    }

    // Update cached copy and storage.
    for (const k in server) {
      const key = k as keyof IConfig;
      this.propagate(key, server[key]);
    }
  }

  /** Get the live reactive config object (read-only, use set() to write). */
  public use(): DeepReadonly<IConfig> {
    return readonly(this.currentConfig);
  }

  /** Wait for the server copy to land (cached-first otherwise). */
  public async wait(): Promise<void> {
    await this.serverPromise;
  }

  private loadCached(): IConfig {
    // get constants for easier access
    const { ALBUM_SORT_FLAGS } = constants;

    const config: IConfig = {
      // general stuff
      version: String(),
      vod_disable: false,
      video_default_quality: '0',
      places_gis: -1,
      places_search_url: 'https://nominatim.openstreetmap.org',
      map_tile_servers: [],
      map_tile_server_url: String(),
      language: String(),
      locale: String(),

      // enabled apps
      systemtags_enabled: false,
      albums_enabled: false,
      recognize_installed: false,
      recognize_enabled: false,
      facerecognition_installed: false,
      facerecognition_enabled: false,
      lens_enabled: false,
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
      video_autoplay: 'true',
      video_loop: false,
      sidebar_filepath: false,
      metadata_in_slideshow: false,
      slideshow_duration: 5,

      // on this day settings
      onthisday_day_range: 3,
      onthisday_photos_per_year: 10,

      // folder settings
      folders_path: String(),
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

  public async hasVersionChanged(): Promise<boolean> {
    await this.wait();
    return this.versionChanged;
  }

  private notifyVersionChanged(version: string) {
    showInfo(t('memories', 'Memories has been updated to {version}. Reload to get the new version.', { version }));
  }

  public async set<K extends keyof IConfig>(setting: K, value: IConfig[K], remote?: string) {
    if (!localSettings.includes(setting)) {
      await axios.put(API.CONFIG(remote ?? setting), {
        value: value?.toString() ?? '',
      });
    }

    this.propagate(setting, value);
  }

  private propagate<K extends keyof IConfig>(key: K, value: IConfig[K]) {
    this.currentConfig[key] = value;

    if (value == null) {
      this.storage.removeItem(`memories_${key}`);
      return;
    }

    if (typeof value === 'object') {
      return;
    }

    this.storage.setItem(`memories_${key}`, value.toString());
  }
}

export default new UserConfig();
