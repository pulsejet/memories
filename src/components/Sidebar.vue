<template>
  <aside class="app-sidebar" v-if="false"></aside>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { subscribe, unsubscribe, emit } from "@nextcloud/event-bus";

export default defineComponent({
  name: "Sidebar",
  components: {},

  data: () => {
    return {
      nativeOpen: false,
    };
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
    open(filename: string) {
      globalThis.OCA.Files.Sidebar.setFullScreenMode?.(true);
      globalThis.OCA.Files.Sidebar.open(filename);
    },

    close() {
      globalThis.OCA.Files.Sidebar.close();
    },

    setTab(tab: string) {
      globalThis.OCA.Files.Sidebar.setActiveTab(tab);
    },

    handleNativeOpen(event: any) {
      this.nativeOpen = true;
      emit("memories:sidebar:opened", event);
    },

    handleNativeClose(event: any) {
      this.nativeOpen = false;
      emit("memories:sidebar:closed", event);
    },
  },
});
</script>

<style scoped lang="scss">
aside.app-sidebar {
  position: fixed;
  right: 0;
  z-index: 100000000;
}
</style>

<style lang="scss">
// Prevent sidebar from becoming too big
aside.app-sidebar {
  max-width: 360px !important;
}
</style>
