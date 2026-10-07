<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Merge {name} with person', { name: $route.params.name }) }}
    </template>

    <div class="outer">
      <FaceList @select="clickFace" />

      <div v-if="processingTotal > 0">
        <NcProgressBar :value="Math.round((processing * 100) / processingTotal)" :error="true" />
      </div>
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
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';
import FaceList from './FaceList.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import client from '@services/dav/client';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { IFileInfo, IFace } from '@typings';

defineOptions({
  name: 'FaceMergeModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const processing = ref(0);
const processingTotal = ref(0);

function open() {
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
}

function cleanup() {
  show.value = false;
}

async function clickFace(face: IFace) {
  const user = route.params.user?.toString() || '';
  const name = route.params.name?.toString() || '';

  const newName = String(face.name || face.cluster_id);

  if (
    !(await utils.confirmDestructive({
      title: t('memories', 'Merge faces'),
      message: t('memories', 'Merge {name} with {newName}?', {
        name: utils.isNumber(name) ? t('memories', 'Unnamed person') : name,
        newName: utils.isNumber(newName) ? t('memories', 'Unnamed person') : newName,
      }),
      confirm: t('memories', 'Continue'),
      confirmClasses: 'error',
      cancel: t('memories', 'Cancel'),
    }))
  ) {
    return;
  }

  if (routeIs.FaceRecognition) {
    if (Number.isInteger(Number(newName))) {
      showError(t('memories', 'You can only merge with a named person'));
      return;
    }
    await dav.faceRecognitionRenamePerson(name, newName);
    await close();
    await router.replace({
      name: 'facerecognition',
      params: { user: face.user_id, name: newName },
    });
    return;
  }

  try {
    // Get all files for current face
    let res = (await client.getDirectoryContents(`/recognize/${user}/faces/${name}`, { details: true })) as any;
    let data: IFileInfo[] = res.data;
    processingTotal.value = data.length;

    // Don't try too much
    let failures = 0;

    // Create move calls
    const calls = data.map((p) => async () => {
      // Short circuit if we have too many failures
      if (failures === 10) {
        showError(t('memories', 'Too many failures, aborting'));
        failures++;
      }
      if (failures >= 10) return;

      // Move to new face with webdav
      try {
        await client.moveFile(
          `/recognize/${user}/faces/${name}/${p.basename}`,
          `/recognize/${face.user_id}/faces/${newName}/${p.basename}`,
        );
      } catch (e) {
        console.error(e);
        showError(t('memories', 'Error while moving {basename}', p));
        failures++;
      } finally {
        processing.value++;
      }
    });
    for await (const _ of dav.runInParallel(calls, 10)) {
      // nothing to do
    }

    // Go to new face
    if (failures === 0) {
      await close();
      await router.replace({
        name: 'recognize',
        params: { user: face.user_id, name: newName },
      });
    }
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Failed to move {name}.', { name }));
  }
}

defineExpose({ open });
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
