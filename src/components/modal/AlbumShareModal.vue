<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t('memories', 'Share Album') }}
    </template>

    <AlbumCollaborators
      v-if="album"
      :album-name="album.basename"
      :collaborators="album.collaborators"
      :public-link="album.publicLink"
      :allow-public-link="true"
      v-slot="{ collaborators }"
    >
      <NcButton
        :aria-label="t('photos', 'Save collaborators for this album.')"
        type="primary"
        :disabled="loadingAddCollaborators"
        @click="handleSetCollaborators(collaborators)"
      >
        <template #icon>
          <XLoadingIcon v-if="loadingAddCollaborators" />
        </template>
        {{ t('photos', 'Save') }}
      </NcButton>
    </AlbumCollaborators>

    <XLoadingIcon class="album-share fill-block" v-else />
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';

import * as dav from '../../services/dav';

import Modal from './Modal.vue';
import AlbumCollaborators from './AlbumCollaborators.vue';

export default defineComponent({
  name: 'AlbumShareModal',
  components: {
    NcButton,
    Modal,
    AlbumCollaborators,
  },

  data: () => ({
    album: null as any,
    show: false,
    loadingAddCollaborators: false,
    collaborators: [] as any[],
  }),

  methods: {
    close() {
      this.show = false;
      this.album = null;
      this.$emit('close');
    },

    async open() {
      this.show = true;
      this.loadingAddCollaborators = true;
      const user = <string>this.$route.params.user || '';
      const name = <string>this.$route.params.name || '';
      this.album = await dav.getAlbum(user, name);
      this.loadingAddCollaborators = false;
    },

    async handleSetCollaborators(collaborators: any[]) {
      try {
        this.loadingAddCollaborators = true;
        await dav.updateAlbum(this.album, {
          albumName: this.album.basename,
          properties: { collaborators },
        });
        this.close();
      } catch (error) {
        console.error(error);
      } finally {
        this.loadingAddCollaborators = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.album-share.loading-icon {
  height: 350px;
}
</style>
