<template>
  <NcModal
    class="memories-modal"
    ref="modal"
    :size="size"
    :outTransition="true"
    :style="{ width: isSidebarShown ? `calc(100% - ${sidebarWidth}px)` : null }"
    :additionalTrapElements="trapElements"
    :canClose="canClose"
    @close="cleanup"
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
import { defineComponent } from 'vue';
import type { PropType } from 'vue';

const NcModal = () => import('@nextcloud/vue/dist/Components/NcModal.js');

import * as utils from '@services/utils';

export default defineComponent({
  name: 'Modal',
  components: {
    NcModal,
  },

  props: {
    size: {
      type: String,
      default: 'small',
    },
    sidebar: {
      type: String as PropType<string | null>,
      default: null,
    },
    canClose: {
      type: Boolean,
      default: true,
    },
  },

  data: () => ({
    isSidebarShown: false,
    sidebarWidth: 400,
    trapElements: [] as HTMLElement[],
    _mutationObserver: null! as MutationObserver,
  }),

  beforeMount() {
    if (this.sidebar) {
      utils.bus.on('memories:sidebar:opened', this.handleAppSidebarOpen);
      utils.bus.on('memories:sidebar:closed', this.handleAppSidebarClose);
    }
    this._mutationObserver = new MutationObserver(this.handleBodyMutation);
    this._mutationObserver.observe(document.body, { childList: true });
  },

  beforeDestroy() {
    if (this.sidebar) {
      utils.bus.off('memories:sidebar:opened', this.handleAppSidebarOpen);
      utils.bus.off('memories:sidebar:closed', this.handleAppSidebarClose);
      _m.sidebar.close();
    }
    this._mutationObserver.disconnect();
  },

  mounted() {
    if (this.sidebar) {
      _m.sidebar.open(0, this.sidebar, true);

      // Adjust width anyway in case the sidebar is already open
      this.handleAppSidebarOpen();
    }
  },

  methods: {
    close() {
      const modal: any = this.$refs.modal;
      if (modal?.close) {
        modal.close();
      } else {
        // Premature calls, before the modal is mounted
        this.cleanup();
      }
    },

    cleanup() {
      this.$emit('close');
    },

    /**
     * Watch out for Popover inject in document root
     * That way we can adjust the focusTrap
     */
    handleBodyMutation(mutations: MutationRecord[]) {
      const test = (node: Node): node is HTMLElement =>
        node instanceof HTMLElement && node?.classList?.contains('v-popper__popper');

      mutations.forEach((mutation) => {
        if (mutation.type === 'childList') {
          Array.from(mutation.addedNodes)
            .filter(test)
            .forEach((node) => this.trapElements.push(node));
          Array.from(mutation.removedNodes)
            .filter(test)
            .forEach((node) => (this.trapElements = this.trapElements.filter((el) => el !== node)));
        }
      });
    },

    handleAppSidebarOpen() {
      const sidebar = document.getElementById('app-sidebar-vue');
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

@media (max-width: 512px) {
  .memories-modal:deep {
    .modal-header {
      display: none !important;
    }

    .modal-wrapper > .modal-container {
      max-height: calc(99% - env(keyboard-inset-height, 0px));
      height: unset;
      top: unset;
      bottom: env(keyboard-inset-height, 0px);

      // Hide scrollbar
      scrollbar-width: none;
      -ms-overflow-style: none;
      &::-webkit-scrollbar {
        display: none;
        width: 0 !important;
      }
    }
  }
}
</style>
