<template>
  <button
    class="me-float-close me-icon-btn me-clickable button-vue"
    aria-label="Close"
    title="Close"
    @click="emit('close')"
  >
    <Close :size="20" />
  </button>
  <button class="me-float-save me-glass me-clickable button-vue" @click="emit('save')">
    {{ saveLabel }}
  </button>
</template>

<script setup lang="ts">
import Close from 'vue-material-design-icons/Close.vue';

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
</script>
