<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      <template v-if="!album">
        {{ t('memories', 'Create new album') }}
      </template>
      <template v-else>
        {{ t('memories', 'Edit album details') }}
      </template>
    </template>

    <div class="outer">
      <AlbumForm :album="album" :display-back-button="false" :title="t('memories', 'New album')" @done="done" />
    </div>
  </Modal>
</template>

<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import Modal from './Modal.vue';
import AlbumForm from './AlbumForm.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

defineOptions({
  name: 'AlbumCreateModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const album = ref<any>(null);

/**
 * Open the modal
 * @param edit If true, the modal will be opened in edit mode
 */
async function open(edit: boolean) {
  if (edit) {
    try {
      album.value = await dav.getAlbum(route.params.user?.toString(), route.params.name?.toString());
    } catch (e) {
      console.error(e);
      showError(t('memories', 'Could not load the selected album'));
      return;
    }
  } else {
    album.value = null;
  }

  show.value = true;
}

function cleanup() {
  show.value = false;
}

async function done({ album: newAlbum }: { album: { basename: string; filename: string } }) {
  // close modal first to pop fragments
  await close();

  // navigate to album if name changed
  if (!album.value || newAlbum.basename !== album.value.basename) {
    const user = newAlbum.filename.split('/')[2];
    const name = newAlbum.basename;
    const target = { name: 'albums', params: { user, name } };

    if (!album.value) {
      await router.push(target);
    } else {
      await router.replace(target);
    }
  } else {
    // refresh timeline for metadata changes
    utils.bus.emit('memories:timeline:soft-refresh', null);
  }
}

defineExpose({ open });
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}

.info-pad {
  margin-top: 6px;
}
</style>
