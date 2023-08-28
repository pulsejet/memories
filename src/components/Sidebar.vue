<template>
  <aside id="app-sidebar-vue" class="app-sidebar reduced" v-if="reducedOpen">
    <div class="title">
      <h2>{{ basename }}</h2>

      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Close')" @click="close()">
          {{ t('memories', 'Close') }}
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <Metadata ref="metadata" />
  </aside>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';

import Metadata from './Metadata.vue';
import { IImageInfo, IPhoto } from '../types';

import * as utils from '../services/utils';

import CloseIcon from 'vue-material-design-icons/Close.vue';

export default defineComponent({
  name: 'Sidebar',
  components: {
    Metadata,
    NcActions,
    NcActionButton,
    CloseIcon,
  },

  data: () => {
    return {
      nativeOpen: false,
      reducedOpen: false,
      basename: '',
      lastKnownWidth: 0,
    };
  },

  computed: {
    native() {
      return globalThis.OCA?.Files?.Sidebar;
    },
  },

  created() {
    if (globalThis.OCA) {
      globalThis.OCA.Files ??= {};

      // TODO: remove when we have a proper fileinfo standalone library
      // original scripts are loaded from
      // https://github.com/nextcloud/server/blob/5bf3d1bb384da56adbf205752be8f840aac3b0c5/lib/private/legacy/template.php#L120-L122
      Object.assign(
        globalThis.OCA.Files,
        {
          App: {
            fileList: {
              filesClient: (<any>globalThis.OC.Files).getClient(),
            },
          },
        },
        globalThis.OCA.Files
      );
    }
  },

  mounted() {
    utils.bus.on('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.on('files:sidebar:closed', this.handleNativeClose);

    globalThis.mSidebar = {
      open: this.open.bind(this),
      close: this.close.bind(this),
      setTab: this.setTab.bind(this),
      getWidth: this.getWidth.bind(this),
    };
  },

  beforeDestroy() {
    utils.bus.off('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.off('files:sidebar:closed', this.handleNativeClose);
  },

  methods: {
    async open(photo: IPhoto | number, filename?: string, useNative = false) {
      if (!this.reducedOpen && this.native && (!photo || useNative)) {
        // Open native sidebar
        this.native?.setFullScreenMode?.(true);
        this.native?.open(filename);
      } else {
        // Open reduced sidebar
        this.reducedOpen = true;
        await this.$nextTick();

        // Update metadata compoenent
        const m = <any>this.$refs.metadata;
        const info: IImageInfo = await m?.update(photo);
        if (!info) return; // failure or state change
        this.basename = info.basename;
        this.handleOpen();
      }
    },

    async close() {
      if (this.nativeOpen) {
        this.native?.close();
      } else {
        if (this.reducedOpen) {
          this.reducedOpen = false;
          await this.$nextTick();
        }
        this.handleClose();
      }
    },

    setTab(tab: string) {
      this.native?.setActiveTab(tab);
    },

    getWidth() {
      const sidebar = document.getElementById('app-sidebar-vue');
      this.lastKnownWidth = sidebar?.offsetWidth || this.lastKnownWidth;
      return (this.lastKnownWidth || 2) - 2;
    },

    handleClose() {
      utils.bus.emit('memories:sidebar:closed', null);
    },

    handleOpen() {
      // Stop sidebar typing from leaking outside
      const sidebar = document.getElementById('app-sidebar-vue');
      sidebar?.addEventListener('keydown', (e) => {
        if (e.key.length === 1) e.stopPropagation();
      });

      utils.bus.emit('memories:sidebar:opened', null);
    },

    handleNativeOpen() {
      this.nativeOpen = true;
      this.handleOpen();
    },

    handleNativeClose() {
      this.nativeOpen = false;
      this.native?.setFullScreenMode?.(false);
      this.handleClose();
    },
  },
});
</script>

<style scoped lang="scss">
#app-sidebar-vue {
  position: fixed;
  top: 0;
  right: 0;
  width: 27vw;
  min-width: 300px;
  height: 100% !important;
  z-index: 2525;
  padding: 10px;
  background-color: var(--color-main-background);
  border-left: 1px solid var(--color-border);

  @media (max-width: 512px) {
    width: 100vw;
    min-width: unset;
  }

  .title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px;

    h2 {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      margin: 0;
    }
  }
}
</style>

<style lang="scss">
// Prevent sidebar from becoming too big
#app-sidebar-vue {
  max-width: 360px !important;
  position: fixed !important;

  &.reduced {
    overflow-y: auto;
  }

  @media (max-width: 512px) {
    max-width: unset !important;
  }
}

// Hack to put the floating dropdown menu above the
// sidebar ... this may have unintended side effects
.vs__dropdown-menu--floating {
  z-index: 2526;
}

// Make metadata tab scrollbar thin
#tab-memories-metadata,
.app-sidebar.reduced {
  scrollbar-width: thin;
  &::-webkit-scrollbar {
    width: 5px;
  }
  &::-webkit-scrollbar-track {
    background: transparent;
  }
}
</style>
