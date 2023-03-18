<template>
  <aside class="app-sidebar" v-if="reducedOpen">
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
    };
  },

  beforeDestroy() {
    unsubscribe("files:sidebar:opened", this.handleNativeOpen);
    unsubscribe("files:sidebar:closed", this.handleNativeClose);
  },

  methods: {
    async open(fileid: number, filename?: string, forceNative = false) {
      if (!this.reducedOpen && this.native && (!fileid || forceNative)) {
        this.native?.setFullScreenMode?.(true);
        this.native?.open(filename);
      } else {
        this.reducedOpen = true;
        await this.$nextTick();
        const info: IImageInfo = await (<any>this.$refs.metadata)?.update(
          fileid
        );
        this.basename = info.basename;
        emit("memories:sidebar:opened", null);
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
        emit("memories:sidebar:closed", null);
      }
    },

    setTab(tab: string) {
      this.native?.setActiveTab(tab);
    },

    handleNativeOpen(event: any) {
      this.nativeOpen = true;
      emit("memories:sidebar:opened", event);
    },

    handleNativeClose(event: any) {
      this.nativeOpen = false;
      this.native?.setFullScreenMode?.(false);
      emit("memories:sidebar:closed", event);
    },
  },
});
</script>

<style scoped lang="scss">
aside.app-sidebar {
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
aside.app-sidebar {
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
