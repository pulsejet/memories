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

<script setup lang="ts">
import { computed, ref, useTemplateRef, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';

import { showInfo } from '@nextcloud/dialogs';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';
import AlbumPicker from './AlbumPicker.vue';

import { useModal } from '@services/modal';
import { n, t } from '@services/l10n';
import { routeIs } from '@services/router';
import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IAlbum, IPhoto } from '@typings';

defineOptions({
  name: 'AddToAlbumModal',
});

const route = useRoute();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const photos = ref<IPhoto[]>([]);
const opsDone = ref(0);
const opsTotal = ref(0);

const progress = computed(() =>
  Math.min(opsTotal.value ? Math.round((opsDone.value * 100) / opsTotal.value) : 100, 100),
);

console.assert(!_m.modals.updateAlbums, 'AddToAlbumModal created twice');
_m.modals.updateAlbums = open;

function open(photosIn: IPhoto[]) {
  photos.value = photosIn;
  opsTotal.value = 0;
  show.value = true;
}

function cleanup() {
  show.value = false;
  photos.value = [];
  opsTotal.value = 0;
}

function routeIsAlbum(album: IAlbum) {
  return routeIs.Albums && route.params.user?.toString() === album.user && route.params.name?.toString() === album.name;
}

async function update(selection: IAlbum[], deselection: IAlbum[]) {
  if (opsTotal.value) return;

  // For now, updats is relevant only for multiple photos
  // and multiple photos do not support deselection anyway.
  // So it is good enough to only emit either op here.
  const processedIds = new Set<number>();

  // Total number of DAV calls (ugh DAV)
  opsTotal.value = photos.value.length * (selection.length + deselection.length);
  opsDone.value = 0;
  let opsSuccess = 0;

  // Process file ids returned from generator
  const processFileIds = (fileIds: number[]) => {
    const successIds = fileIds.filter(Boolean);
    successIds.forEach((f) => processedIds.add(f));
    opsDone.value += fileIds.length;
    opsSuccess += successIds.length;
  };

  // Add the photos to the selected albums
  for (const album of selection) {
    for await (const fileIds of dav.addToAlbum(album.user, album.name, photos.value)) {
      processFileIds(fileIds);
    }
  }

  // Remove the photos from the deselected albums
  for (const album of deselection) {
    for await (const fids of dav.removeFromAlbum(album.user, album.name, photos.value)) {
      processFileIds(fids);

      // Update current view if required
      if (routeIsAlbum(album)) {
        const deleted = photos.value.filter((p) => fids.includes(p.fileid));
        utils.bus.emit('memories:timeline:deleted', deleted);
      }
    }
  }

  const nPhotos = processedIds.size;
  showInfo(n('memories', '{n} photo updated', '{n} photos updated', nPhotos, { n: nPhotos }));

  // emit only the successfully processed photos here
  // so that only these are deselected by the manager
  const processedPhotos = photos.value.filter((p) => processedIds.has(p.fileid));
  utils.bus.emit('memories:albums:update', processedPhotos);

  // close the modal only if all ops are successful
  if (opsSuccess === opsTotal.value) {
    close();
  } else {
    opsTotal.value = 0;

    // remove the photos that were processed successfully
    // so that the user can try again with the remaining ones
    photos.value = photos.value.filter((p) => !processedIds.has(p.fileid));
  }
}
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}

.progress-bar {
  margin-top: 10px;
}
</style>
