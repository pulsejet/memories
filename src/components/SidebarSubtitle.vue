<template>
  <div class="sidebar-subtitle">
    <span v-if="size">{{ utils.humanFileSize(size) }}</span>
    <span v-if="mtime">{{ utils.getFromNowStr(new Date(mtime * 1000)) }}</span>
  </div>
</template>

<script setup lang="ts">
import * as utils from '@services/utils';

withDefaults(
  defineProps<{
    /** File size in bytes, 0 hides the field */
    size?: number;
    /** Modification time as epoch seconds, 0 hides the field */
    mtime?: number;
  }>(),
  {
    size: 0,
    mtime: 0,
  },
);
</script>

<style lang="scss" scoped>
.sidebar-subtitle {
  color: var(--color-text-maxcontrast);
  display: flex;
  gap: 0 12px;
}
</style>
