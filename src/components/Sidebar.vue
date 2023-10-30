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
import Vue, { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';

import Metadata from '@components/Metadata.vue';

import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import InfoSvg from '@assets/info.svg';

export default defineComponent({
  name: 'Sidebar',
  components: {
    Metadata,
    NcActions,
    NcActionButton,
    CloseIcon,
  },

  data: () => ({
    nativeOpen: false,
    reducedOpen: false,
    basename: String(),
    lastKnownWidth: 0,
    nativeMetadata: null as null | InstanceType<typeof Metadata>,
  }),

  computed: {
    refs() {
      return this.$refs as {
        metadata?: InstanceType<typeof Metadata>;
      };
    },

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
      const filesClient = (<any>globalThis.OC.Files).getClient();
      Object.assign(globalThis.OCA.Files, { App: { fileList: { filesClient } } }, globalThis.OCA.Files);
    }
  },

  mounted() {
    utils.bus.on('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.on('files:sidebar:closed', this.handleNativeClose);
    utils.bus.on('memories:fragment:pop:sidebar', this.close);

    _m.sidebar = {
      open: this.open.bind(this),
      close: this.close.bind(this),
      setTab: this.setTab.bind(this),
      invalidateUnless: this.invalidateUnless.bind(this),
      getWidth: this.getWidth.bind(this),
    };

    // Register native tab after DOMContentLoaded
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', this.registerNative.bind(this)); // wait for it
    } else {
      this.registerNative(); // already fired
    }
  },

  beforeDestroy() {
    utils.bus.off('files:sidebar:opened', this.handleNativeOpen);
    utils.bus.off('files:sidebar:closed', this.handleNativeClose);
    utils.bus.off('memories:fragment:pop:sidebar', this.close);
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
        const info = await this.refs.metadata?.update(photo);
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

    invalidateUnless(fileid: number) {
      this.refs.metadata?.invalidateUnless(fileid);
      this.nativeMetadata?.invalidateUnless(fileid);
    },

    getWidth() {
      const sidebar = document.getElementById('app-sidebar-vue');
      this.lastKnownWidth = sidebar?.offsetWidth || this.lastKnownWidth;
      return (this.lastKnownWidth || 2) - 2;
    },

    handleClose() {
      utils.bus.emit('memories:sidebar:closed', null);
      utils.fragment.pop(utils.fragment.types.sidebar);
    },

    handleOpen() {
      // Stop sidebar typing from leaking outside
      const sidebar = document.getElementById('app-sidebar-vue');
      sidebar?.addEventListener('keydown', (e) => {
        if (e.key.length === 1) e.stopPropagation();
      });

      // Emit event
      utils.bus.emit('memories:sidebar:opened', null);

      // Use fragment navigation only on mobile
      if (utils.isMobile()) {
        utils.fragment.push(utils.fragment.types.sidebar);
      }
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

    /** Register the Nextcloud Sidebar component */
    async registerNative() {
      // Wait just in case the sidebar isn't avaialble yet
      await new Promise((resolve) => setTimeout(resolve, 100));

      // Pass router to the component
      const router = this.$router;

      // Component instance
      let component: any;
      const self = this;

      // Register sidebar tab
      globalThis.OCA?.Files?.Sidebar?.registerTab(
        new globalThis.OCA.Files.Sidebar.Tab({
          id: 'memories-metadata',
          name: this.t('memories', 'Info'),
          icon: 'icon-details',
          iconSvg: window.atob(InfoSvg.split(',')[1]), // base64 to svg

          mount(el: HTMLElement, fileInfo: { id: string | number }, context: any) {
            component?.$destroy?.();
            component = new Vue({ render: (h) => h(Metadata), router });
            component.$mount(el);

            self.nativeMetadata = component.$children[0];
            self.nativeMetadata?.update(Number(fileInfo.id));
          },

          update(fileInfo: { id: string | number }) {
            self.nativeMetadata?.update(Number(fileInfo.id));
          },

          destroy() {
            component?.$destroy?.();
            component = null;
            self.nativeMetadata = null;
          },
        }),
      );
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
