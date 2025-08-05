<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
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
import { defineComponent, defineAsyncComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';
import * as dav from '@services/dav';

export default defineComponent({
  name: 'FaceDeleteModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  computed: {
    name() {
      return this.$route.params.name;
    },

    user() {
      return this.$route.params.user;
    },
  },

  methods: {
    open() {
      if (this.user !== utils.uid) {
        showError(this.t('memories', 'Only user "{user}" can delete this person', { user: this.user }));
        return;
      }

      this.show = true;
    },

    cleanup() {
      this.show = false;
    },

    async save() {
      try {
        if (this.routeIsRecognize) {
          await dav.recognizeDeleteFace(this.user, this.name);
        } else {
          await dav.faceRecognitionSetPersonVisibility(this.name, false);
        }
        this.$router.push({ name: this.$route.name as string }); // "recognize" or "facerecognition"
        this.close();
      } catch (error) {
        console.log(error);
        showError(this.t('memories', 'Failed to delete {name}.', { name: this.name }));
      }
    },
  },
});
</script>
