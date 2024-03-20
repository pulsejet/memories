<template>
  <NcButton class="memories-upload-menu" :aria-label="t('memories', 'Upload')" type="primary" @click="upload">
    <template #icon> <UploadIcon :size="20" /> </template>
  </NcButton>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import UploadIcon from 'vue-material-design-icons/Upload.vue';

export default defineComponent({
  name: 'UploadMenuItem',
  components: {
    NcButton,
    UploadIcon,
  },

  methods: {
    upload() {
      _m.modals.upload();
    },
  },
});
</script>

<style scoped lang="scss">
.memories-upload-menu {
  background-color: transparent !important;
  color: var(--color-primary-text) !important;
  opacity: 0.85;
  &:hover {
    opacity: 1;
  }
}
</style>
