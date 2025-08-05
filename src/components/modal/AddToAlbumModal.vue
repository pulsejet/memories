<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
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
import { defineComponent, defineAsyncComponent } from 'vue';

import { showInfo } from '@nextcloud/dialogs';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumPicker from './AlbumPicker.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IAlbum, IPhoto } from '@typings';

export default defineComponent({
  name: 'AddToAlbumModal',
  components: {
    NcProgressBar,
    Modal,
    AlbumPicker,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    photos: [] as IPhoto[],
    opsDone: 0,
    opsTotal: 0,
  }),

  computed: {
    progress(): number {
      return Math.min(this.opsTotal ? Math.round((this.opsDone * 100) / this.opsTotal) : 100, 100);
    },
  },

  created() {
    console.assert(!_m.modals.updateAlbums, 'AddToAlbumModal created twice');
    _m.modals.updateAlbums = this.open;
  },

  methods: {
    open(photos: IPhoto[]) {
      this.photos = photos;
      this.opsTotal = 0;
      this.show = true;
    },

    cleanup() {
      this.show = false;
      this.photos = [];
      this.opsTotal = 0;
    },

    routeIsAlbum(album: IAlbum) {
      return this.routeIsAlbums && this.$route.params.user === album.user && this.$route.params.name === album.name;
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
        const successIds = fileIds.filter(Boolean);
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

          // Update current view if required
          if (this.routeIsAlbum(album)) {
            const photos = this.photos.filter((p) => fids.includes(p.fileid));
            utils.bus.emit('memories:timeline:deleted', photos);
          }
        }
      }

      const n = processedIds.size;
      showInfo(this.n('memories', '{n} photo updated', '{n} photos updated', n, { n }));

      // emit only the successfully processed photos here
      // so that only these are deselected by the manager
      const processedPhotos = this.photos.filter((p) => processedIds.has(p.fileid));
      utils.bus.emit('memories:albums:update', processedPhotos);

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
