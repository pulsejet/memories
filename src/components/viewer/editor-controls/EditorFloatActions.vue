<template>
  <button
    class="me-float-close me-icon-btn me-clickable button-vue"
    aria-label="Close"
    title="Close"
    @click="onClose"
  >
    <Close :size="20" />
  </button>
  <button class="me-float-save me-glass me-clickable button-vue" @click="onSave">
    {{ saveLabel }}
  </button>
</template>

<script setup lang="ts">
import Close from 'vue-material-design-icons/Close.vue';
import * as nativex from '@native';

withDefaults(
  defineProps<{
    saveLabel?: string;
  }>(),
  { saveLabel: 'Save' },
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
  nativex.playTouchSound();
  emit('save');
}
</script>
