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
import { emit } from '@nextcloud/event-bus';

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

  mounted() {
    console.assert(!globalThis.updateAlbums, 'AddToAlbumModal mounted twice');
    globalThis.updateAlbums = this.open;
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

      // For now, updats is relevant only for multiple photos
      // and multiple photos do not support deselection anyway.
      // So it is good enough to only emit either op here.
      const processedIds = new Set<number>();

      // Total number of DAV calls (ugh DAV)
      this.opsTotal = this.photos.length * (selection.length + deselection.length);
      this.opsDone = 0;
      let opsSuccess = 0;

      // Process file ids returned from generator
      const processFileIds = (fileIds: number[]) => {
        const successIds = fileIds.filter((f) => f);
        successIds.forEach((f) => processedIds.add(f));
        this.opsDone += fileIds.length;
        opsSuccess += successIds.length;
      };

      // Add the photos to the selected albums
      for (const album of selection) {
        for await (const fileIds of dav.addToAlbum(album.user, album.name, this.photos)) {
          processFileIds(fileIds);
        }
      }

      // Remove the photos from the deselected albums
      for (const album of deselection) {
        for await (const fids of dav.removeFromAlbum(album.user, album.name, this.photos)) {
          processFileIds(fids);
        }
      }

      const n = processedIds.size;
      showInfo(this.n('memories', '{n} photo updated', '{n} photos updated', n, { n }));

      // emit only the successfully processed photos here
      // so that only these are deselected by the manager
      const processedPhotos = this.photos.filter((p) => processedIds.has(p.fileid));
      emit('memories:albums:update', processedPhotos);

      // close the modal only if all ops are successful
      if (opsSuccess === this.opsTotal) {
        this.close();
      } else {
        this.opsTotal = 0;

        // remove the photos that were processed successfully
        // so that the user can try again with the remaining ones
        this.photos = this.photos.filter((p) => !processedIds.has(p.fileid));
      }
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
