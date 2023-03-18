<template>
  <aside id="app-sidebar-vue" class="app-sidebar" v-if="reducedOpen">
    <div class="title">
      <h2>{{ basename }}</h2>

      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Close')" @click="close()">
          {{ t("memories", "Close") }}
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <Metadata ref="metadata" />
  </aside>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { subscribe, unsubscribe, emit } from "@nextcloud/event-bus";

import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";

import Metadata from "./Metadata.vue";
import { IImageInfo } from "../types";

import CloseIcon from "vue-material-design-icons/Close.vue";

export default defineComponent({
  name: "Sidebar",
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
      basename: "",
      lastKnownWidth: 0,
    };
  },

  computed: {
    native() {
      return globalThis.OCA?.Files?.Sidebar;
    },
  },

  mounted() {
    subscribe("files:sidebar:opened", this.handleNativeOpen);
    subscribe("files:sidebar:closed", this.handleNativeClose);

    globalThis.mSidebar = {
      open: this.open.bind(this),
      close: this.close.bind(this),
      setTab: this.setTab.bind(this),
      getWidth: this.getWidth.bind(this),
    };
  },

  beforeDestroy() {
    unsubscribe("files:sidebar:opened", this.handleNativeOpen);
    unsubscribe("files:sidebar:closed", this.handleNativeClose);
  },

  methods: {
    async open(fileid: number, filename?: string, forceNative = false) {
      if (!this.reducedOpen && this.native && (!fileid || forceNative)) {
        // Open native sidebar
        this.native?.setFullScreenMode?.(true);
        this.native?.open(filename);
      } else {
        // Open reduced sidebar
        this.reducedOpen = true;
        await this.$nextTick();

        // Update metadata compoenent
        const m = <any>this.$refs.metadata;
        const info: IImageInfo = await m?.update(fileid);
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
      const sidebar = document.getElementById("app-sidebar-vue");
      this.lastKnownWidth = sidebar?.offsetWidth || this.lastKnownWidth;
      return (this.lastKnownWidth || 2) - 2;
    },

    handleClose() {
      emit("memories:sidebar:closed", null);
    },

    handleOpen() {
      // Stop sidebar typing from leaking outside
      const sidebar = document.getElementById("app-sidebar-vue");
      sidebar?.addEventListener("keydown", (e) => {
        if (e.key.length === 1) e.stopPropagation();
      });

      emit("memories:sidebar:opened", null);
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

  @media (max-width: 512px) {
    max-width: unset !important;
  }
}

// Hack to put the floating dropdown menu above the
// sidebar ... this may have unintended side effects
.vs__dropdown-menu--floating {
  z-index: 2526;
}
</style>
