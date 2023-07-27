<template>
  <Modal @close="close" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Add to album') }}
    </template>

    <div class="outer">
      <AlbumPicker @select="updateAlbums" :photos="photos" />

      <div v-if="processing">
        <NcProgressBar :value="progress" :error="true" />
      </div>
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as dav from '../../services/DavRequests';
import { showInfo } from '@nextcloud/dialogs';
import { IAlbum, IPhoto } from '../../types';

const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar');

import Modal from './Modal.vue';
import AlbumPicker from './AlbumPicker.vue';

export default defineComponent({
  name: 'AddToAlbumModal',
  components: {
    NcProgressBar,
    Modal,
    AlbumPicker,
  },

  data: () => ({
    show: false,
    photos: [] as IPhoto[],
    progress: 0,
    processing: false,
    processed: new Set<IPhoto>(),
    photosDone: 0,
    totalOperations: 0,
  }),

  methods: {
    open(photos: IPhoto[]) {
      this.progress = 0;
      this.processing = false;
      this.show = true;
      this.photos = photos;
    },

    added(photos: IPhoto[]) {
      this.$emit('added', photos);
    },

    close() {
      this.photos = [];
      this.processing = false;
      this.show = false;
      this.$emit('close');
    },

    async processAlbum(album: IAlbum, action: 'add' | 'remove') {
      const name = album.name || album.album_id.toString();
      const gen = action === 'add'
        ? dav.addToAlbum(album.user, name, this.photos)
        : dav.removeFromAlbum(album.user, name, this.photos);
      
      for await (const fids of gen) {
        this.photosDone += fids.length;
        this.photos.forEach((p) => {
          if (fids.includes(p.fileid)) {
            this.processed.add(p);
          }
        });
      }
      this.progress = Math.round((this.photosDone * 100) / this.totalOperations);
    },

    async updateAlbums(albumsToAddTo: IAlbum[], albumsToRemoveFrom: IAlbum[] = []) {
      if (this.processing) return;
      this.processing = true;
      this.processed = new Set<IPhoto>();
      this.totalOperations = this.photos.length * (albumsToAddTo.length + albumsToRemoveFrom.length);

      await Promise.all(albumsToAddTo.map((album) => this.processAlbum(album, 'add')));
      await Promise.all(albumsToRemoveFrom.map((album) => this.processAlbum(album, 'remove')));
      const n = this.processed.size;
      this.added(Array.from(this.processed));
      showInfo(this.n('memories', '{n} processed', '{n} processed', n, { n }));
      this.close();
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
