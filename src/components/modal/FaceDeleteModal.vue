<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t('memories', 'Remove person') }}
    </template>

    <span>{{ t('memories', 'Are you sure you want to remove {name}?', { name }) }}</span>

    <template #buttons>
      <NcButton @click="save" class="button" type="error">
        {{ t('memories', 'Delete') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');

import { showError } from '@nextcloud/dialogs';

import Modal from './Modal.vue';

import * as utils from '../../services/utils';
import * as dav from '../../services/dav';

export default defineComponent({
  name: 'FaceDeleteModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  emits: [],

  data: () => ({
    show: false,
    user: '',
    name: '',
  }),

  mounted() {
    this.refreshParams();
  },

  watch: {
    $route() {
      this.refreshParams();
    },
  },

  methods: {
    close() {
      this.show = false;
    },

    open() {
      const user = this.$route.params.user || '';
      if (this.$route.params.user !== utils.uid) {
        showError(
          this.t('memories', 'Only user "{user}" can delete this person', {
            user,
          }),
        );
        return;
      }
      this.show = true;
    },

    refreshParams() {
      this.user = <string>this.$route.params.user || '';
      this.name = <string>this.$route.params.name || '';
    },

    async save() {
      try {
        if (this.$route.name === 'recognize') {
          await dav.recognizeDeleteFace(this.user, this.name);
        } else {
          await dav.faceRecognitionSetPersonVisibility(this.name, false);
        }
        this.$router.push({ name: this.$route.name as string });
        this.close();
      } catch (error) {
        console.log(error);
        showError(
          this.t('photos', 'Failed to delete {name}.', {
            name: this.name,
          }),
        );
      }
    },
  },
});
</script>
