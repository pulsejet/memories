<template>
  <div class="sidebar-subtitle">
    <span v-if="size">{{ utils.humanFileSize(size) }}</span>
    <span v-if="mtime">{{ utils.getFromNowStr(new Date(mtime * 1000)) }}</span>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'SidebarSubtitle',

  props: {
    /** File size in bytes, 0 hides the field */
    size: {
      type: Number,
      required: false,
      default: 0,
    },
    /** Modification time as epoch seconds, 0 hides the field */
    mtime: {
      type: Number,
      required: false,
      default: 0,
    },
  },

  data: () => ({
    utils: Object.freeze(utils),
  }),
});
</script>

<style lang="scss" scoped>
.sidebar-subtitle {
  color: var(--color-text-lighter);
  display: flex;
  gap: 0 12px;
}
</style>
