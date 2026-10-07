<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Move to folder') }}
    </template>

    <div class="outer">
      <NcProgressBar :value="Math.round((photosDone * 100) / photos.length)" :error="true" />
    </div>
  </Modal>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, defineAsyncComponent } from 'vue';

import { showInfo } from '@services/utils/dialog';

const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t, n } from '@services/l10n';
import { config } from '@services/user-config';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { IPhoto } from '@typings';

defineOptions({
  name: 'MoveToFolderModal',
});

const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const photos = ref<IPhoto[]>([]);
const photosDone = ref(0);

console.assert(!_m.modals.moveToFolder, 'MoveToFolderModal created twice');
_m.modals.moveToFolder = open;

function open(photosIn: IPhoto[]) {
  photosDone.value = 0;
  show.value = false;
  photos.value = photosIn;
  chooseFolderPath();
}

function cleanup() {
  show.value = false;
  photos.value = [];
}

async function chooseFolderPath() {
  enum Mode {
    Move = 1,
    Copy = 2,
    Organise = 3,
  }
  let mode: Mode = Mode.Move as Mode;
  let destination = await utils.chooseNcFolder(t('memories', 'Choose a folder'), config.folders_path, () => [
    {
      label: 'Move and organise',
      callback: () => void (mode = Mode.Organise),
    },
    {
      label: 'Copy',
      callback: () => void (mode = Mode.Copy),
    },
    {
      label: 'Move',
      type: 'primary',
      callback: () => void (mode = Mode.Move),
    },
  ]);

  // Fails if the target exists, same behavior with Nextcloud files implementation.
  let gen = (() => {
    switch (mode) {
      case Mode.Organise: {
        return dav.movePhotosByDate(photos.value, destination, false);
      }
      case Mode.Copy: {
        return dav.copyPhotos(photos.value, destination, false);
      }
      case Mode.Move: {
        return dav.movePhotos(photos.value, destination, false);
      }
    }
  })();

  show.value = true;

  for await (const fids of gen) {
    photosDone.value += fids.filter(Boolean).length;
    utils.bus.emit('memories:timeline:soft-refresh', null);
  }

  const nPhotos = photosDone.value;
  if (mode === Mode.Copy) {
    showInfo(n('memories', '{n} item copied to folder', '{n} items copied to folder', nPhotos, { n: nPhotos }));
  } else {
    showInfo(n('memories', '{n} item moved to folder', '{n} items moved to folder', nPhotos, { n: nPhotos }));
  }
  close();
}
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
