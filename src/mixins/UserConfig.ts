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
    utils.bus.on(eventName, this.updateLocalSetting);
    this.refreshFromConfig();
  },

  beforeUnmount() {
    utils.bus.off(eventName, this.updateLocalSetting);
  },

  methods: {
    async refreshFromConfig() {
      const config = await staticConfig.getAll();
      const changed = (Object.keys(config) as (keyof IConfig)[]).some((key) => config[key] !== this.config[key]);
      if (!changed) return;

      this.config = { ...config };
      utils.bus.emit(eventName, null);
    },

    updateLocalSetting(val: { setting: keyof IConfig; value: IConfig[keyof IConfig] } | null) {
      if (val?.setting) {
        (this.config as any)[val.setting] = val.value;
      }
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
