<template>
  <NcButton
    class="memories-menu-item upload-menu"
    :title="t('memories', 'Upload')"
    :aria-label="t('memories', 'Upload')"
    @click="upload"
  >
    <template #icon>
      <PlusIcon :size="20" v-if="plusIcon" />
      <UploadIcon :size="20" v-else />
    </template>
  </NcButton>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import UploadIcon from 'vue-material-design-icons/Upload.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';

export default defineComponent({
  name: 'UploadMenuItem',
  components: {
    NcButton,
    UploadIcon,
    PlusIcon,
  },

  props: {
    plusIcon: {
      type: Boolean,
      default: false,
    },
  },

  methods: {
    upload() {
      _m.modals.upload();
    },
  },
});
</script>
