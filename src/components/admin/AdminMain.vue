<template>
  <div class="outer" v-if="config && sconfig">
    <XLoadingIcon class="loading-icon" v-show="loading" />

    <div class="left-pane">
      <component
        v-for="c in components"
        :id="c.name"
        :key="c.name"
        :is="c"
        :status="status"
        :config="config"
        :sconfig="sconfig"
        @update="update"
      />
    </div>
    <div class="right-pane">
      <a class="sec-link" v-for="c in components" :key="c.name" :href="`#${c.name}`">{{ c.title ?? c.name }}</a>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { API } from '@services/API';
import * as utils from '@services/utils';
import staticConfig from '@services/static-config';

import Help from './sections/Help.vue';
import Exif from './sections/Exif.vue';
import Indexing from './sections/Indexing.vue';
import FileSupport from './sections/FileSupport.vue';
import Viewer from './sections/ViewerAdmin.vue';
import Performance from './sections/Performance.vue';
import Apps from './sections/Apps.vue';
import Places from './sections/Places.vue';
import Video from './sections/Video.vue';
import VideoTranscoder from './sections/VideoTranscoder.vue';
import VideoAccel from './sections/VideoAccel.vue';

import type { ISystemConfig, ISystemStatus } from './AdminTypes';
import type { IConfig } from '@typings';

export default defineComponent({
  name: 'Admin',

  data: () => ({
    loading: 0,

    status: null as ISystemStatus | null,
    config: null as ISystemConfig | null,
    sconfig: null as IConfig | null,

    components: [
      Help,
      Exif,
      Indexing,
      FileSupport,
      Viewer,
      Performance,
      Apps,
      Places,
      Video,
      VideoTranscoder,
      VideoAccel,
    ],
  }),

  mounted() {
    this.refreshSystemConfig();
    this.refreshStatus();
    this.refreshStaticConfig();
  },

  methods: {
    async refreshSystemConfig() {
      try {
        this.loading++;
        const res = await axios.get<ISystemConfig>(API.SYSTEM_CONFIG(null));
        this.config = res.data;
      } catch (e) {
        showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
        console.error(e);
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
        showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
        console.error(e);
      } finally {
        this.loading--;
      }
    },

    async refreshStaticConfig() {
      try {
        this.loading++;
        this.sconfig = await staticConfig.getAll();
      } catch (e) {
        showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
        console.error(e);
      } finally {
        this.loading--;
      }
    },

    async update<K extends keyof ISystemConfig>(key: K, value: ISystemConfig[K] | null = null) {
      if (!this.config?.hasOwnProperty(key)) {
        console.error('Unknown setting', key);
        return;
      }

      // Get final value
      value ??= this.config[key];
      this.config[key] = value;

      try {
        this.loading++;
        await axios.put(API.SYSTEM_CONFIG(key), { value });

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
  overflow-x: hidden;

  > .right-pane {
    display: none;
  }

  @media (min-width: 1024px) {
    display: flex;
    flex-direction: row;
    height: 100%;

    > .left-pane {
      flex: 1;
      padding-right: 10px;
      height: 100%;
      overflow-y: auto;
    }

    > .right-pane {
      display: block;
      padding: 10px;
      line-height: 2em;
      > a.sec-link {
        display: block;
      }
    }
  }

  :deep a {
    color: var(--color-primary-element);
  }

  :deep .admin-section {
    margin-top: 20px;

    form {
      margin-top: 1em;
    }

    .checkbox-radio-switch {
      margin: 2px 16px;
    }

    .m-radio {
      display: inline-block;
    }

    .input-field {
      // Prevent overlapping label with another input
      margin-top: 0.8em;
    }

    h2 {
      font-size: 1.6em;
      font-weight: 500;
      padding-top: 20px;
    }

    h3 {
      font-size: 1.2em;
      font-weight: 500;
      padding-top: 10px;
    }

    code {
      padding-left: 10px;
      -webkit-box-decoration-break: clone;
      box-decoration-break: clone;
    }

    b {
      font-weight: 500;
    }
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
}
</style>
