<template>
  <Modal @close="close" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Add to album') }}
    </template>

    <div class="outer">
      <AlbumPicker @select="selectAlbums" :photos="photos" />

      <div v-if="processing">
        <NcProgressBar :value="Math.round((photosDone * 100) / photos.length)" :error="true" />
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
    photosDone: 0,
    processing: false,
  }),

  methods: {
    open(photos: IPhoto[]) {
      this.photosDone = 0;
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

    async selectAlbums(albums: IAlbum[]) {
      if (this.processing) return;
      const processed = new Set<IPhoto>();
      const photosDone = new Set<number>();

      await Promise.all(albums.map(async (album) => { 
        const name = album.name || album.album_id.toString();
        const gen = dav.addToAlbum(album.user, name, this.photos);
        this.processing = true;
        
        for await (const fids of gen) {
          fids.forEach((f) => {
            if (f) {
              photosDone.add(f);
            }
          });
          this.photos.forEach((p) => {
            if (fids.includes(p.fileid)) {
              processed.add(p);
            }
          });
        }
        this.photosDone = photosDone.size;
      }));
      const n = this.photosDone;
      this.added(Array.from(processed));
      showInfo(this.n('memories', '{n} item added to album', '{n} items added to album', n, { n }));
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
