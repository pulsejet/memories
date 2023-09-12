import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';

import { API } from '../services/API';
import * as utils from '../services/utils';

import { IConfig } from '../types';
import staticConfig from '../services/static-config';

const eventName: keyof utils.BusEvent = 'memories:user-config-changed';

const localSettings: (keyof IConfig)[] = ['square_thumbs', 'high_res_cond', 'show_face_rect', 'album_list_sort'];

export default defineComponent({
  name: 'UserConfig',

  data: () => ({
    config: { ...staticConfig.getDefault() },
  }),

  created() {
    utils.bus.on(eventName, this.updateLocalSetting);
    this.refreshFromConfig();
  },

  beforeDestroy() {
    utils.bus.off(eventName, this.updateLocalSetting);
  },

  methods: {
    async refreshFromConfig() {
      const config = await staticConfig.getAll();
      const changed = Object.keys(config).filter((key) => config[key] !== this.config[key]);
      if (changed.length === 0) return;

      changed.forEach((key) => (this.config[key] = config[key]));
      utils.bus.emit(eventName, null);
    },

    updateLocalSetting({ setting, value }) {
      if (setting) {
        this.config[setting] = value;
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
