<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t('memories', 'Remove Album') }}
    </template>

    <span>
      {{ t('memories', 'Are you sure you want to permanently remove album "{name}"?', { name }) }}
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
import { getCurrentUser } from '@nextcloud/auth';
import Modal from './Modal.vue';
import client from '../../services/DavClient';

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
    $route: async function (from: any, to: any) {
      this.refreshParams();
    },
  },

  mounted() {
    this.refreshParams();
  },

  methods: {
    close() {
      this.show = false;
      this.$emit('close');
    },

    open() {
      const user = this.$route.params.user || '';
      if (this.$route.params.user !== getCurrentUser()?.uid) {
        showError(
          this.t('memories', 'Only user "{user}" can delete this album', {
            user,
          })
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
        await client.deleteFile(`/photos/${this.user}/albums/${this.name}`);
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
