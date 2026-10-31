<template>
  <button
    class="me-float-close me-icon-btn me-clickable button-vue"
    :aria-label="t('memories', 'Close')"
    :title="t('memories', 'Close')"
    @click="onClose"
  >
    <Close :size="20" />
  </button>
  <button class="me-float-save me-glass me-clickable button-vue" :disabled="disabled" @click="onSave">
    {{ t('memories', 'Save') }}
  </button>
</template>

<script setup lang="ts">
import Close from 'vue-material-design-icons/Close.vue';
import * as nativex from '@native';
import { t } from '@services/l10n';

const props = withDefaults(
  defineProps<{
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{
  close: [];
  save: [];
}>();

function onClose() {
  nativex.playTouchSound();
  emit('close');
}

function onSave() {
  if (props.disabled) return;
  nativex.playTouchSound();
  emit('save');
}
</script>
