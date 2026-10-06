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

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, markRaw } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { API } from '@services/API';
import * as utils from '@services/utils';
import staticConfig from '@services/static-config';
import { t } from '@services/l10n';

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
import XLoadingIcon from '@components/XLoadingIcon.vue';

import type { ISystemConfig, ISystemStatus } from './AdminTypes';
import type { IConfig } from '@typings';

const loading = ref(0);

const status = ref<ISystemStatus | null>(null);
const config = ref<ISystemConfig | null>(null);
const sconfig = ref<IConfig | null>(null);

const refreshTimer = new utils.RenewingTimeout();

const components = [
  markRaw(Help),
  markRaw(Exif),
  markRaw(Indexing),
  markRaw(FileSupport),
  markRaw(Viewer),
  markRaw(Performance),
  markRaw(Apps),
  markRaw(Places),
  markRaw(Video),
  markRaw(VideoTranscoder),
  markRaw(VideoAccel),
];

onMounted(() => {
  refreshSystemConfig();
  refreshStatus();
  refreshStaticConfig();
  utils.bus.on('memories:user-config-changed', refreshStaticConfig);
});

onBeforeUnmount(() => {
  utils.bus.off('memories:user-config-changed', refreshStaticConfig);
});

async function refreshSystemConfig() {
  try {
    loading.value++;
    const res = await axios.get<ISystemConfig>(API.SYSTEM_CONFIG(null));
    config.value = res.data;
  } catch (e: any) {
    showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
    console.error(e);
  } finally {
    loading.value--;
  }
}

async function refreshStatus() {
  try {
    loading.value++;
    const res = await axios.get<ISystemStatus>(API.SYSTEM_STATUS());
    status.value = res.data;
  } catch (e: any) {
    showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
    console.error(e);
  } finally {
    loading.value--;
  }
}

async function refreshStaticConfig() {
  try {
    loading.value++;
    sconfig.value = await staticConfig.getAll();
  } catch (e: any) {
    showError(JSON.stringify(e.response?.data?.message ?? e.response?.data ?? e));
    console.error(e);
  } finally {
    loading.value--;
  }
}

async function update<K extends keyof ISystemConfig>(key: K, value: ISystemConfig[K] | null = null) {
  if (!config.value || !Object.hasOwn(config.value, key)) {
    console.error('Unknown setting', key);
    return;
  }

  // Get final value
  value ??= config.value[key];
  config.value[key] = value;

  try {
    loading.value++;
    await axios.put(API.SYSTEM_CONFIG(key), { value });

    refreshTimer.set(refreshStatus, 500);
  } catch (err) {
    console.error(err);
    showError(t('memories', 'Failed to update setting'));
  } finally {
    loading.value--;
  }
}
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

  :deep(a) {
    color: var(--color-primary-element);
  }

  :deep(.admin-section) {
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

    :deep(svg) {
      width: 100%;
      height: 100%;
    }
  }
}
</style>
