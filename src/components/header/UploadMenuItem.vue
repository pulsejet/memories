<template>
  <NcButton
    class="memories-menu-item upload-menu"
    variant="tertiary-no-background"
    :title="t('memories', 'Upload')"
    :aria-label="t('memories', 'Upload')"
    @click="upload"
  >
    <template #icon>
      <UploadIcon :size="20" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton';
import UploadIcon from 'vue-material-design-icons/CloudUpload.vue';

import { t } from '@services/l10n';

function upload() {
  _m.modals.upload();
}
</script>
