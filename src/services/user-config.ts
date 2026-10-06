import { reactive, onBeforeUnmount } from 'vue';

import axios from '@nextcloud/axios';

import { API } from '@services/API';
import staticConfig from '@services/static-config';
import * as utils from '@services/utils';

import type { IConfig } from '@typings';

/** Fired when any setting changes (null payload resyncs everything). */
const eventName = 'memories:user-config-changed' as const;

/** Settings stored locally only, never sent to the server. */
const localSettings: (keyof IConfig)[] = ['square_thumbs', 'high_res_cond', 'show_face_rect'];

/** Local reactive copy of the user config, kept in sync over the bus. */
export function useUserConfig() {
  const config = reactive({ ...staticConfig.getDefault() });

  /** Copy fresh values over from the static config if anything changed. */
  function syncFromStaticConfig() {
    const fresh = staticConfig.getDefault();
    const changed = (Object.keys(fresh) as (keyof IConfig)[]).some((key) => fresh[key] !== config[key]);
    if (changed) {
      Object.assign(config, fresh);
    }
  }

  /** Apply a bus notification: single setting or full resync. */
  function onConfigChanged(val: { setting: keyof IConfig; value: IConfig[keyof IConfig] } | null) {
    if (val?.setting) {
      (config as any)[val.setting] = val.value;
    } else {
      syncFromStaticConfig();
    }
  }

  /** Persist one setting and notify other components. */
  async function updateSetting<K extends keyof IConfig>(setting: K, remote?: string) {
    const value = config[setting];

    if (!localSettings.includes(setting)) {
      await axios.put(API.CONFIG(remote ?? setting), {
        value: value?.toString() ?? '',
      });
    }

    staticConfig.setLs(setting, value);
    utils.bus.emit(eventName, { setting, value });
  }

  utils.bus.on(eventName, onConfigChanged);
  onBeforeUnmount(() => {
    utils.bus.off(eventName, onConfigChanged);
  });

  return { config, updateSetting };
}
