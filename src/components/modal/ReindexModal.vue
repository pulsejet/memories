<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Refresh metadata') }}
    </template>

    <div class="reindex">
      <div>
        {{
          n('memories', 'Processing {n} file', 'Processing {n} files', photos.length, {
            n: photos.length,
          })
        }}
      </div>
      <NcProgressBar :value="Math.round((photosDone * 100) / Math.max(photos.length, 1))" :error="true" />
    </div>
  </Modal>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, defineAsyncComponent } from 'vue';

import { showInfo } from '@nextcloud/dialogs';

const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { n, t } from '@services/l10n';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { IPhoto } from '@typings';

defineOptions({
  name: 'ReindexModal',
});

const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const photos = ref<IPhoto[]>([]);
const photosDone = ref(0);

console.assert(!_m.modals.reindex, 'ReindexModal created twice');
_m.modals.reindex = open;

function open(photosIn: IPhoto[]) {
  photos.value = photosIn;
  photosDone.value = 0;
  if (!photosIn.length) return;
  show.value = true;
  void run();
}

function cleanup() {
  show.value = false;
  photos.value = [];
}

async function run() {
  const ok = await dav.reindexPhotos(photos.value, (done) => {
    photosDone.value = done;
  });

  showInfo(n('memories', '{n} file refreshed', '{n} files refreshed', ok, { n: ok }));
  close();
  utils.bus.emit('memories:timeline:soft-refresh', null);
}
</script>

<style lang="scss" scoped>
.reindex {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
