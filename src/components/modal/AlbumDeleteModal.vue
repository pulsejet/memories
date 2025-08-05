<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ owned ? t('memories', 'Remove Album') : t('memories', 'Leave Album') }}
    </template>

    <span>
      {{
        owned
          ? t('memories', 'Are you sure you want to permanently remove album "{name}"?', { name })
          : t('memories', 'Are you sure you want to leave the shared album "{name}"?', { name })
      }}
    </span>

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
import client from '@services/dav/client';

export default defineComponent({
  name: 'AlbumDeleteModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  computed: {
    user() {
      return this.$route.params.user;
    },

    name() {
      return this.$route.params.name;
    },

    owned() {
      return this.user === utils.uid;
    },
  },

  methods: {
    open() {
      this.show = true;
    },

    cleanup() {
      this.show = false;
    },

    async save() {
      try {
        await client.deleteFile(dav.getAlbumPath(this.user, this.name));
        this.$router.push({ name: 'albums' });
        this.close();
      } catch (error) {
        console.log(error);
        showError(this.t('memories', 'Failed to delete {name}.', { name: this.name }));
      }
    },
  },
});
</script>
