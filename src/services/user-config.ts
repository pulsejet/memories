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

/**
 * Server-side names for settings that differ from local keys.
 * Settings missing here are sent to the server under their own key.
 */
const remoteNames: Partial<Record<keyof IConfig, string>> = {
  timeline_path: 'timelinePath',
  folders_path: 'foldersPath',
  enable_top_memories: 'enableTopMemories',
  stack_raw_files: 'stackRawFiles',
  dedup_identical: 'dedupIdentical',
  show_owner_name_timeline: 'showOwnerNameTimeline',
  livephoto_autoplay: 'livephotoAutoplay',
  livephoto_loop: 'livephotoLoop',
  video_autoplay: 'videoAutoplay',
  video_loop: 'videoLoop',
  sidebar_filepath: 'sidebarFilepath',
  metadata_in_slideshow: 'metadataInSlideshow',
  slideshow_duration: 'slideshowDuration',
  onthisday_day_range: 'onthisdayDayRange',
  onthisday_photos_per_year: 'onthisdayPhotosPerYear',
  show_hidden_folders: 'showHidden',
  show_hidden_albums: 'showHiddenAlbums',
  sort_folder_month: 'sortFolderMonth',
  sort_album_month: 'sortAlbumMonth',
  map_tile_server_url: 'mapTileServerUrl',
};

/**
 * Settings stored locally only, never sent to the server.
 * These are still persisted to browser storage via propagate().
 */
const localSettings: (keyof IConfig)[] = ['square_thumbs', 'high_res_cond', 'show_face_rect'];

/** Namespaced browser storage for cached config values. Cleared on logout. */
const storage = getBuilder('memories').clearOnLogout().persist().build();

/** Live reactive config. Mutated in place so all readers stay in sync. */
const currentConfig: IConfig = reactive(loadCached());

/**
 * Read-only view of the live config for components.
 * Use setConfig() to write; direct mutation throws.
 */
export const config: DeepReadonly<IConfig> = readonly(currentConfig);

/** Whether the server reported a different app version than the cache. */
let versionChanged = false;

/** Resolves once the server copy has landed (or failed). @see waitForConfig */
const serverPromise = fetchServer();

/** Fetch the server copy and propagate it into the live config. */
async function fetchServer() {
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
  const old = { ...currentConfig } as IConfig;

  // Check if version changed
  if (old.version !== server.version) {
    versionChanged = true;

    // Let the user know they might need a page refresh to get a new version.
    // None of the callers know about the old version, so we need to do this here.
    if (!nativex && old.version) {
      notifyVersionChanged(server.version);
    }

    // Clear page cache, keep other caches
    window.caches?.delete('memories-pages');
  }

  // Update cached copy and storage.
  // Keys missing from the server (e.g. local settings) are skipped,
  // so the cached values are kept as is.
  for (const k in server) {
    const key = k as keyof IConfig;
    propagate(key, server[key]);
  }
}

/**
 * Wait for the server copy to land (cached-first otherwise).
 * @returns promise that resolves once fetchServer() settles.
 */
export function waitForConfig(): Promise<void> {
  return serverPromise;
}

/**
 * Build the initial config from hardcoded defaults overlaid with cached values.
 * @returns the cached config object (made reactive by the caller).
 */
function loadCached(): IConfig {
  // get constants for easier access
  const { ALBUM_SORT_FLAGS } = constants;

  const defaults: IConfig = {
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

  /**
   * Overlay one cached value onto the defaults, coercing to the default's type.
   * @param key setting to load.
   * @param value raw cached string, or null if never stored.
   */
  const set = <K extends keyof IConfig, V extends IConfig[K]>(key: K, value: string | null) => {
    if (value == null) return;

    if (typeof defaults[key] === 'boolean') {
      defaults[key] = (value === 'true') as V;
    } else if (typeof defaults[key] === 'number') {
      const n = Number(value);
      if (Number.isFinite(n)) defaults[key] = n as V;
    } else {
      defaults[key] = value as V;
    }
  };

  for (const key in defaults) {
    set(key as keyof IConfig, storage.getItem(`memories_${key}`));
  }

  return defaults;
}

/**
 * Check if the server reported a different app version than the cache.
 * @returns true if the version changed (caller should prompt for reload).
 */
export async function hasVersionChanged(): Promise<boolean> {
  await serverPromise;
  return versionChanged;
}

/**
 * Inform the user that a new app version is available.
 * @param version the new version reported by the server.
 */
function notifyVersionChanged(version: string) {
  showInfo(t('memories', 'Memories has been updated to {version}. Reload to get the new version.', { version }));
}

/**
 * Persist one setting to the server (unless local-only) and propagate it
 * into the live config. All readers update automatically.
 * @param setting setting to update.
 * @param value new value to store.
 */
export async function setConfig<K extends keyof IConfig>(setting: K, value: IConfig[K]) {
  if (!localSettings.includes(setting)) {
    await axios.put(API.CONFIG(remoteNames[setting] ?? setting), {
      value: value?.toString() ?? '',
    });
  }

  propagate(setting, value);
}

/**
 * Write one setting into the live config and mirror it to browser storage.
 * Objects are kept in memory only; null removes the stored value.
 * @param key setting to write.
 * @param value new value to store.
 */
function propagate<K extends keyof IConfig>(key: K, value: IConfig[K]) {
  currentConfig[key] = value;

  if (value == null) {
    storage.removeItem(`memories_${key}`);
    return;
  }

  if (typeof value === 'object') {
    return;
  }

  storage.setItem(`memories_${key}`, value.toString());
}
