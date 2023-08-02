<template>
  <Modal @close="close" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Add to album') }}
    </template>

    <div class="outer">
      <AlbumPicker @select="update" :photos="photos" :disabled="!!opsTotal" />

      <div class="progress-bar" v-if="opsTotal">
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
    opsDone: 0,
    opsTotal: 0,
  }),

  computed: {
    progress(): number {
      return Math.min(this.opsTotal ? Math.round((this.opsDone * 100) / this.opsTotal) : 100, 100);
    },
  },

  methods: {
    open(photos: IPhoto[]) {
      this.photos = photos;
      this.show = true;
      this.opsTotal = 0;
    },

    close() {
      this.show = false;
      this.photos = [];
      this.opsTotal = 0;
      this.$emit('close');
    },

    async update(selection: IAlbum[], deselection: IAlbum[]) {
      if (this.opsTotal) return;

      // Total number of DAV calls (ugh DAV)
      this.opsTotal = this.photos.length * (selection.length + deselection.length);

      // Add the photos to the selected albums
      for (const album of selection) {
        for await (const fids of dav.addToAlbum(album.user, album.name, this.photos)) {
          this.opsDone += fids.filter((f) => f).length;
        }
      }

      // Remove the photos from the deselected albums
      for (const album of deselection) {
        for await (const fids of dav.removeFromAlbum(album.user, album.name, this.photos)) {
          this.opsDone += fids.filter((f) => f).length;
        }
      }

      const n = this.photos.length;
      showInfo(this.n('memories', '{n} photo updated', '{n} photos updated', n, { n }));

      this.$emit('change');
      this.close();
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}

.progress-bar {
  margin-top: 10px;
}
</style>
