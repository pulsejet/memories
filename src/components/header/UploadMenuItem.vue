<template>
  <NcButton
    class="memories-upload-menu"
    :title="t('memories', 'Upload')"
    :aria-label="t('memories', 'Upload')"
    type="primary"
    @click="upload"
  >
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
