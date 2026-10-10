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

import { showError } from '@services/utils/dialog';

import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

defineOptions({
  name: 'FaceDeleteModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());
const abort = useAbort();

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can delete this person', { user: user.value }));
    return;
  }

  show.value = true;
}

function cleanup() {
  abort.abort();
  show.value = false;
}

async function save() {
  const signal = abort.renew();
  try {
    if (routeIs.Recognize) {
      await dav.recognizeDeleteFace(user.value, name.value, { signal });
    } else {
      await dav.faceRecognitionSetPersonVisibility(name.value, false, { signal });
    }
    signal.throwIfAborted();
    router.push({ name: route.name?.toString() }); // "recognize" or "facerecognition"
    close();
  } catch (error) {
    if (isAbortError(error)) return;
    console.error(error);
    showError(t('memories', 'Failed to delete {name}.', { name: name.value }));
  }
}

defineExpose({ open });
</script>
