<template>
  <div class="outer" v-if="loaded">
    <NcLoadingIcon class="loading-icon" v-show="loading" />

    <component v-for="c in components" :key="c.__name" :is="c" :status="status" :config="config" @update="update" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { API } from '../../services/API';
import * as utils from '../../services/Utils';

import Exif from './sections/Exif.vue';
import Indexing from './sections/Indexing.vue';
import Performance from './sections/Performance.vue';
import Places from './sections/Places.vue';
import Video from './sections/Video.vue';
import VideoTranscoder from './sections/VideoTranscoder.vue';
import VideoAccel from './sections/VideoAccel.vue';

import { ISystemConfig, ISystemStatus } from './AdminTypes';

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon';

export default defineComponent({
  name: 'Admin',
  components: {
    NcLoadingIcon,
  },

  data: () => ({
    loaded: false,
    loading: 0,

    status: null as ISystemStatus | null,
    config: null as ISystemConfig | null,

    components: [Exif, Indexing, Performance, Places, Video, VideoTranscoder, VideoAccel],
  }),

  mounted() {
    this.refreshSystemConfig();
    this.refreshStatus();
  },

  methods: {
    async refreshSystemConfig() {
      try {
        this.loading++;
        const res = await axios.get<ISystemConfig>(API.SYSTEM_CONFIG(null));
        this.config = res.data;
        this.loaded = true;
      } catch (e) {
        showError(JSON.stringify(e));
      } finally {
        this.loading--;
      }
    },

    async refreshStatus() {
      try {
        this.loading++;
        const res = await axios.get<ISystemStatus>(API.SYSTEM_STATUS());
        this.status = res.data;
      } catch (e) {
        showError(JSON.stringify(e));
      } finally {
        this.loading--;
      }
    },

    async update(key: keyof ISystemConfig, value: any = null) {
      if (!this.config?.hasOwnProperty(key)) {
        console.error('Unknown setting', key);
        return;
      }

      // Get final value
      value ??= this.config[key];
      this.config[key as string] = value;

      try {
        this.loading++;
        await axios.put(API.SYSTEM_CONFIG(key), {
          value: value,
        });

        utils.setRenewingTimeout(this, '_refreshTimer', this.refreshStatus.bind(this), 500);
      } catch (err) {
        console.error(err);
        showError(this.t('memories', 'Failed to update setting'));
      } finally {
        this.loading--;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  padding: 20px;
  padding-top: 0px;

  .admin-section {
    margin-top: 20px;
  }

  .loading-icon {
    top: 10px;
    right: 20px;
    position: absolute;
    width: 28px;
    height: 28px;

    :deep svg {
      width: 100%;
      height: 100%;
    }
  }

  form {
    margin-top: 1em;
  }

  .checkbox-radio-switch {
    margin: 2px 8px;
  }

  .m-radio {
    display: inline-block;
  }

  :deep h2 {
    font-size: 1.5em;
    font-weight: bold;
    margin-top: 25px;
  }

  :deep h3 {
    font-size: 1.2em;
    font-weight: 500;
    margin-top: 20px;
  }

  :deep a {
    color: var(--color-primary-element);
  }

  :deep code {
    padding-left: 10px;
    -webkit-box-decoration-break: clone;
    box-decoration-break: clone;
  }
}
</style>
