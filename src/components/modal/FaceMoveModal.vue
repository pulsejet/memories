<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Move selected photos to person') }}
    </template>

    <div class="outer">
      <FaceList :plus="true" @select="clickFace" />
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" variant="error">
        {{ t('memories', 'Cancel') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Cluster from '@components/frame/Cluster.vue';
import FaceList from './FaceList.vue';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IPhoto, IFace } from '@typings';

defineOptions({
  name: 'FaceMoveModal',
});

const route = useRoute();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const photos = ref<IPhoto[]>([]);

console.assert(!_m.modals.moveToFace, 'FaceMoveModal created twice');
_m.modals.moveToFace = open;

function open(photosIn: IPhoto[]) {
  if (photos.value.length) {
    // is processing
    return;
  }

  // check ownership
  const user = route.params.user?.toString() || '';
  if (route.params.user?.toString() !== utils.uid) {
    showError(
      t('memories', 'Only user "{user}" can update this person', {
        user,
      }),
    );
    return;
  }

  show.value = true;
  photos.value = photosIn;
}

function cleanup() {
  show.value = false;
  photos.value = [];
}

function moved(photosIn: IPhoto[]) {
  utils.bus.emit('memories:timeline:deleted', photosIn);
}

async function clickFace(face: IFace) {
  const user = route.params.user?.toString() || '';
  const name = route.params.name?.toString() || '';
  const target = String(face.name || face.cluster_id);

  if (
    !(await utils.confirmDestructive({
      title: t('memories', 'Move to person'),
      message: t('memories', 'Move the selected photos to {target}?', {
        target: utils.isNumber(target) ? t('memories', 'Unnamed person') : target,
      }),
      confirm: t('memories', 'Move'),
      confirmClasses: 'primary',
      cancel: t('memories', 'Cancel'),
    }))
  ) {
    return;
  }

  try {
    // Create map to return IPhoto later
    const map = new Map<number, IPhoto>();
    for (const photo of photos.value.filter((p) => p.faceid)) {
      map.set(photo.faceid!, photo);
    }

    // Run WebDAV query
    const photosArr = Array.from(map.values());
    for await (let delIds of dav.recognizeMoveFaceImages(user, name, target, photosArr)) {
      moved(
        delIds
          .filter(utils.truthy)
          .map((id) => map.get(id))
          .filter(utils.truthy),
      );
    }
  } catch (error) {
    console.error(error);
    showError(t('memories', 'An error occurred while moving photos from {name}.', { name }));
  } finally {
    close();
  }
}
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
