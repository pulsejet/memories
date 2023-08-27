<template>
  <Modal @close="close" v-if="show">
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
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');
import { showError } from '@nextcloud/dialogs';
import Modal from './Modal.vue';

import * as utils from '../../services/utils';
import * as dav from '../../services/dav';
import client from '../../services/dav/client';

export default defineComponent({
  name: 'AlbumDeleteModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  data: () => ({
    show: false,
    user: '',
    name: '',
  }),

  watch: {
    $route() {
      this.refreshParams();
    },
  },

  mounted() {
    this.refreshParams();
  },

  computed: {
    owned() {
      return this.user === utils.uid;
    },
  },

  methods: {
    close() {
      this.show = false;
      this.$emit('close');
    },

    open() {
      this.show = true;
    },

    refreshParams() {
      this.user = this.$route.params.user ?? String();
      this.name = this.$route.params.name ?? String();
    },

    async save() {
      try {
        await client.deleteFile(dav.getAlbumPath(this.user, this.name));
        this.$router.push({ name: 'albums' });
        this.close();
      } catch (error) {
        console.log(error);
        showError(
          this.t('photos', 'Failed to delete {name}.', {
            name: this.name,
          })
        );
      }
    },
  },
});
</script>
