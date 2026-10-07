<template>
  <NcModal
    class="memories-modal"
    ref="modal"
    labelId="modal-title"
    :size="size"
    :outTransition="true"
    :style="{ width: isSidebarShown ? `calc(100% - ${sidebarWidth}px)` : null }"
    :additionalTrapElements="trapElements"
    :canClose="canClose"
    @close="cleanup"
  >
    <div class="container" @keydown.stop="0">
      <div class="head">
        <span id="modal-title">
          <slot name="title"></slot>
        </span>
      </div>

      <slot></slot>

      <div class="buttons">
        <slot name="buttons"></slot>
      </div>
    </div>
  </NcModal>
</template>

<script setup lang="ts">
import {
  ref,
  useTemplateRef,
  computed,
  markRaw,
  onBeforeMount,
  onBeforeUnmount,
  onMounted,
  defineAsyncComponent,
} from 'vue';

const NcModal = defineAsyncComponent(() => import('@nextcloud/vue/components/NcModal'));

import * as utils from '@services/utils/common';

defineOptions({
  name: 'Modal',
});

const props = defineProps<{
  size?: 'small' | 'normal' | 'large' | 'full';
  sidebar?: string | null;
  canClose?: boolean;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
}>();

const size = computed(() => props.size ?? 'small');
const sidebar = computed(() => props.sidebar ?? null);
const canClose = computed(() => props.canClose ?? true);

const modal = useTemplateRef<{ close?: () => void }>('modal');

const isSidebarShown = ref(false);
const sidebarWidth = ref(400);
const trapElements = ref<HTMLElement[]>([]);
let _mutationObserver!: MutationObserver;

onBeforeMount(() => {
  if (sidebar.value) {
    utils.bus.on('memories:sidebar:opened', handleAppSidebarOpen);
    utils.bus.on('memories:sidebar:closed', handleAppSidebarClose);
  }
  _mutationObserver = markRaw(new MutationObserver(handleBodyMutation));
  _mutationObserver.observe(document.body, { childList: true });
});

onBeforeUnmount(() => {
  if (sidebar.value) {
    utils.bus.off('memories:sidebar:opened', handleAppSidebarOpen);
    utils.bus.off('memories:sidebar:closed', handleAppSidebarClose);
    _m.sidebar.close();
  }
  _mutationObserver.disconnect();
});

onMounted(() => {
  if (sidebar.value) {
    _m.sidebar.open(0, sidebar.value, true);

    // Adjust width anyway in case the sidebar is already open
    handleAppSidebarOpen();
  }
});

function close() {
  if (modal.value?.close) {
    modal.value.close();
  } else {
    // Premature calls, before the modal is mounted
    cleanup();
  }
}

function cleanup() {
  emit('close');
}

/**
 * Watch out for Popover inject in document root
 * That way we can adjust the focusTrap
 */
function handleBodyMutation(mutations: MutationRecord[]) {
  const test = (node: Node): node is HTMLElement =>
    node instanceof HTMLElement && node?.classList?.contains('v-popper__popper');

  mutations.forEach((mutation) => {
    if (mutation.type === 'childList') {
      Array.from(mutation.addedNodes)
        .filter(test)
        .forEach((node) => trapElements.value.push(node));
      Array.from(mutation.removedNodes)
        .filter(test)
        .forEach((node) => (trapElements.value = trapElements.value.filter((el) => el !== node)));
    }
  });
}

function handleAppSidebarOpen() {
  const sidebarEl = document.getElementById('app-sidebar-vue') ?? document.getElementById('app-sidebar-native');
  if (sidebarEl) {
    isSidebarShown.value = true;
    sidebarWidth.value = sidebarEl.offsetWidth;
    trapElements.value = [sidebarEl];
  }
}

function handleAppSidebarClose() {
  isSidebarShown.value = false;
  trapElements.value = [];
}

defineExpose({ close });
</script>

<style lang="scss" scoped>
.container {
  margin: 20px;

  .head {
    font-weight: 500;
    font-size: 1.15em;
    margin-bottom: 5px;
  }

  :deep(.buttons) {
    margin-top: 10px;
    text-align: right;

    > button {
      display: inline-block !important;
    }
  }
}

@media (max-width: 512px) {
  .memories-modal {
    :deep(.modal-header) {
      display: none !important;
    }

    :deep(.modal-wrapper > .modal-container) {
      max-height: calc(99% - env(keyboard-inset-height, 0px));
      height: unset;
      top: unset;
      bottom: env(keyboard-inset-height, 0px);

      // Hide scrollbar
      scrollbar-width: none;
      -ms-overflow-style: none;
    }

    :deep(.modal-wrapper > .modal-container::-webkit-scrollbar) {
      display: none;
      width: 0 !important;
    }
  }
}
</style>
