<template>
  <button class="me-float-close me-icon-btn me-clickable button-vue" aria-label="Close" title="Close" @click="onClose">
    <Close :size="20" />
  </button>
  <button class="me-float-save me-glass me-clickable button-vue" :disabled="disabled" @click="onSave">
    {{ saveLabel }}
  </button>
</template>

<script setup lang="ts">
import Close from 'vue-material-design-icons/Close.vue';
import * as nativex from '@native';

const props = withDefaults(
  defineProps<{
    saveLabel?: string;
    disabled?: boolean;
  }>(),
  { saveLabel: 'Save', disabled: false },
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
