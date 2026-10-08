<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ owned ? t('memories', 'Remove Album') : t('memories', 'Leave Album') }}
    </template>

    <span>
      {{
        owned
          ? t('memories', 'Are you sure you want to permanently remove album "{name}"?', { name })
          : t('memories', 'Are you sure you want to leave the shared album "{name}"?', { name })
      }}
    </span>

    <template #buttons>
      <NcButton @click="save" class="button" variant="error">
        {{ t('memories', 'Delete') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@services/utils/dialog';
import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';
import client from '@services/dav/client';

defineOptions({
  name: 'AlbumDeleteModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);

const user = computed(() => route.params.user?.toString());
const name = computed(() => route.params.name?.toString());
const owned = computed(() => user.value === utils.uid);

function open() {
  show.value = true;
}

function cleanup() {
  show.value = false;
}

async function save() {
  try {
    await client.deleteFile(dav.getAlbumPath(user.value, name.value));
    await close();
    await router.push({ name: 'albums' });
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Failed to delete {name}.', { name: name.value }));
  }
}

defineExpose({ open });
</script>
