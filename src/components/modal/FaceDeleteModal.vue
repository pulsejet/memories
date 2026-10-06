<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Remove person') }}
    </template>

    <span>{{ t('memories', 'Are you sure you want to remove {name}?', { name }) }}</span>

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

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { useRouteIsRecognize } from '@services/route-checker';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

defineOptions({
  name: 'FaceDeleteModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const routeIsRecognize = useRouteIsRecognize();

const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can delete this person', { user: user.value }));
    return;
  }

  show.value = true;
}

function cleanup() {
  show.value = false;
}

async function save() {
  try {
    if (routeIsRecognize.value) {
      await dav.recognizeDeleteFace(user.value, name.value);
    } else {
      await dav.faceRecognitionSetPersonVisibility(name.value, false);
    }
    router.push({ name: route.name?.toString() }); // "recognize" or "facerecognition"
    close();
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Failed to delete {name}.', { name: name.value }));
  }
}

defineExpose({ open });
</script>
