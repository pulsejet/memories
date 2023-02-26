<template>
  <NcModal
    :size="size"
    :outTransition="true"
    :style="{ width: isSidebarShown ? `calc(100% - ${sidebarWidth}px)` : null }"
    :additionalTrapElements="trapElements"
    @close="close"
  >
    <div class="container" @keydown.stop="0">
      <div class="head">
        <span> <slot name="title"></slot> </span>
      </div>

      <slot></slot>

      <div class="buttons">
        <slot name="buttons"></slot>
      </div>
    </div>
  </NcModal>
</template>

<script lang="ts">
import { defineComponent } from "vue";

const NcModal = () => import("@nextcloud/vue/dist/Components/NcModal");
import { subscribe, unsubscribe } from "@nextcloud/event-bus";

export default defineComponent({
  name: "Modal",
  components: {
    NcModal,
  },

  props: {
    size: {
      type: String,
      default: "small",
    },
    sidebar: {
      type: String,
      default: null,
    },
  },

  data: () => ({
    isSidebarShown: false,
    sidebarWidth: 400,
    trapElements: [],
  }),

  beforeMount() {
    if (this.sidebar) {
      subscribe("files:sidebar:opened", this.handleAppSidebarOpen);
      subscribe("files:sidebar:closed", this.handleAppSidebarClose);
      window.addEventListener("DOMNodeInserted", this.handlePopover);
      globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(true);
    }
  },

  beforeDestroy() {
    if (this.sidebar) {
      unsubscribe("files:sidebar:opened", this.handleAppSidebarOpen);
      unsubscribe("files:sidebar:closed", this.handleAppSidebarClose);
      window.removeEventListener("DOMNodeInserted", this.handlePopover);
      globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(false);
      globalThis.OCA?.Files?.Sidebar?.close();
    }
  },

  mounted() {
    if (this.sidebar) {
      globalThis.OCA.Files.Sidebar.open(this.sidebar);
    }
  },

  methods: {
    close() {
      this.$emit("close");
    },

    /**
     * Watch out for Popover inject in document root
     * That way we can adjust the focusTrap
     */
    handlePopover(event) {
      if (
        event.target?.classList &&
        event.target.classList.contains("v-popper__popper")
      ) {
        this.trapElements.push(event.target);
      }
    },

    handleAppSidebarOpen() {
      this.isSidebarShown = true;
      const sidebar: HTMLElement = document.querySelector("aside.app-sidebar");
      if (sidebar) {
        this.sidebarWidth = sidebar.offsetWidth;
        this.trapElements = [sidebar];
      }
    },

    handleAppSidebarClose() {
      this.isSidebarShown = false;
      this.trapElements = [];
    },
  },
});
</script>

<style lang="scss" scoped>
.container {
  margin: 20px;

  .head {
    font-weight: 500;
    font-size: 1.15em;
    margin-bottom: 5px;
  }

  :deep .buttons {
    margin-top: 10px;
    text-align: right;

    > button {
      display: inline-block !important;
    }
  }
}
</style>
