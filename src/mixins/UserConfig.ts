import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as utils from '@services/utils';
import staticConfig from '../services/static-config';

import type { IConfig } from '@typings';

const eventName = 'memories:user-config-changed' as const;

const localSettings: (keyof IConfig)[] = ['square_thumbs', 'high_res_cond', 'show_face_rect'];

export default defineComponent({
  name: 'UserConfig',

  data: () => ({
    config: { ...staticConfig.getDefault() } as IConfig,
  }),

  created() {
    utils.bus.on(eventName, this.onConfigChanged);
    this.syncFromStaticConfig();
  },

  beforeUnmount() {
    utils.bus.off(eventName, this.onConfigChanged);
  },

  methods: {
    onConfigChanged(val: { setting: keyof IConfig; value: IConfig[keyof IConfig] } | null) {
      if (val?.setting) {
        (this.config as any)[val.setting] = val.value;
      } else {
        this.syncFromStaticConfig();
      }
    },

    syncFromStaticConfig() {
      const fresh = staticConfig.getDefault();
      const changed = (Object.keys(fresh) as (keyof IConfig)[]).some((key) => fresh[key] !== this.config[key]);
      if (changed) {
        this.config = { ...fresh };
      }
    },

    async refreshFromConfig() {
      this.syncFromStaticConfig();
    },

    async updateSetting<K extends keyof IConfig>(setting: K, remote?: string) {
      const value = this.config[setting];

      if (!localSettings.includes(setting)) {
        await axios.put(API.CONFIG(remote ?? setting), {
          value: value?.toString() ?? '',
        });
      }

      staticConfig.setLs(setting, value);

      utils.bus.emit(eventName, { setting, value });
    },
  },
});
