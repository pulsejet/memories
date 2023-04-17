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
    _mutationObserver: null,
  }),

  beforeMount() {
    if (this.sidebar) {
      subscribe("memories:sidebar:opened", this.handleAppSidebarOpen);
      subscribe("memories:sidebar:closed", this.handleAppSidebarClose);
    }
    this._mutationObserver = new MutationObserver(this.handleBodyMutation);
    this._mutationObserver.observe(document.body, { childList: true });
  },

  beforeDestroy() {
    if (this.sidebar) {
      unsubscribe("memories:sidebar:opened", this.handleAppSidebarOpen);
      unsubscribe("memories:sidebar:closed", this.handleAppSidebarClose);
      globalThis.mSidebar.close();
    }
    this._mutationObserver.disconnect();
  },

  mounted() {
    if (this.sidebar) {
      globalThis.mSidebar.open(0, this.sidebar, true);

      // Adjust width anyway in case the sidebar is already open
      this.handleAppSidebarOpen();
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
    handleBodyMutation(mutations: MutationRecord[]) {
      const test = (node: HTMLElement) =>
        node?.classList?.contains("v-popper__popper");

      mutations.forEach((mutation) => {
        if (mutation.type === "childList") {
          Array.from(mutation.addedNodes)
            .filter(test)
            .forEach((node) => this.trapElements.push(node));
          Array.from(mutation.removedNodes)
            .filter(test)
            .forEach(
              (node) =>
                (this.trapElements = this.trapElements.filter(
                  (el) => el !== node
                ))
            );
        }
      });
    },

    handleAppSidebarOpen() {
      const sidebar = document.getElementById("app-sidebar-vue");
      if (sidebar) {
        this.isSidebarShown = true;
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
