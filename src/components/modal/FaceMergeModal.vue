<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      <template v-if="sources.length === 1">
        {{ t('memories', 'Merge {name} with person', { name: faceName(sources[0]) }) }}
      </template>
      <template v-else>
        {{
          n('memories', 'Merge {n} person with person', 'Merge {n} people with person', sources.length, {
            n: sources.length,
          })
        }}
      </template>
    </template>

    <div class="outer">
      <FaceList :user="user" :exclude="sources.map(sourceName)" @select="clickFace" />

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

import { showError } from '@services/utils/dialog';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';
import FaceList from './FaceList.vue';

import { useModal } from '@services/modal';
import { t, n } from '@services/l10n';
import { useRouteIs } from '@services/route-is';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import client from '@services/dav/client';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { IFileInfo, IFace } from '@typings';

defineOptions({
  name: 'FaceMergeModal',
});

const emit = defineEmits<{
  merged: [];
}>();

const route = useRoute();
const router = useRouter();
const routeIs = useRouteIs();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const processing = ref(0);
const processingTotal = ref(0);
const abort = useAbort();

/** Faces to merge into the selected person */
const sources = ref<IFace[]>([]);
const user = ref(String());

/**
 * Open the merge modal.
 * @param faces Faces to merge (defaults to the current person)
 */
function open(faces?: IFace[]) {
  if (!faces) {
    const name = route.params.name?.toString() || '';
    const user_id = route.params.user?.toString() || '';
    const cluster_type = routeIs.FaceRecognition ? 'facerecognition' : 'recognize';
    faces = [{ cluster_id: name, cluster_type, count: 0, name, user_id }];
  }

  const other = faces.find((f) => f.user_id !== utils.uid);
  if (other) {
    showError(
      t('memories', 'Only user "{user}" can update this person', {
        user: other.user_id,
      }),
    );
    return;
  }

  sources.value = faces;
  user.value = faces[0].user_id;
  show.value = true;
}

function cleanup() {
  abort.abort();
  show.value = false;
  processing.value = 0;
  processingTotal.value = 0;
}

function sourceName(face: IFace) {
  return String(face.name || face.cluster_id);
}

function faceName(face: IFace) {
  const name = sourceName(face);
  return utils.isNumber(name) ? t('memories', 'Unnamed person') : name;
}

/** Go to the merged person, or just reload the list if merging from there */
async function done(face: IFace, newName: string) {
  await close();
  if (!route.params.name) {
    emit('merged');
    return;
  }
  await router.replace({
    name: routeIs.FaceRecognition ? 'facerecognition' : 'recognize',
    params: { user: face.user_id, name: newName },
  });
}

async function clickFace(face: IFace) {
  const newName = String(face.name || face.cluster_id);
  const names = sources.value.map(sourceName);

  if (
    !(await utils.confirmDestructive({
      title: t('memories', 'Merge faces'),
      message:
        names.length === 1
          ? t('memories', 'Merge {name} with {newName}?', {
              name: faceName(sources.value[0]),
              newName: faceName(face),
            })
          : n('memories', 'Merge {n} person with {newName}?', 'Merge {n} people with {newName}?', names.length, {
              n: names.length,
              newName: faceName(face),
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
    const signal = abort.renew();
    for (const name of names) {
      try {
        await dav.faceRecognitionRenamePerson(name, newName, { signal });
        signal.throwIfAborted();
      } catch (error) {
        if (isAbortError(error)) return;
        console.error(error);
        showError(t('memories', 'Failed to move {name}.', { name }));
        return;
      }
    }
    await done(face, newName);
    return;
  }

  let name = String();
  try {
    // Get all files for faces being merged
    const signal = abort.renew();
    const files: { name: string; basename: string }[] = [];
    for (name of names) {
      const res = (await client.getDirectoryContents(`/recognize/${user.value}/faces/${name}`, {
        details: true,
        signal,
      })) as any;
      signal.throwIfAborted();
      files.push(...(res.data as IFileInfo[]).map((p) => ({ name, basename: p.basename })));
    }
    processingTotal.value = files.length;

    // Don't try too much
    let failures = 0;

    // Create move calls
    const calls = files.map((p) => async () => {
      // Short circuit if we have too many failures
      if (failures === 10) {
        showError(t('memories', 'Too many failures, aborting'));
        failures++;
      }
      if (failures >= 10) return;
      signal.throwIfAborted();

      // Move to new face with webdav
      try {
        await client.moveFile(
          `/recognize/${user.value}/faces/${p.name}/${p.basename}`,
          `/recognize/${face.user_id}/faces/${newName}/${p.basename}`,
          { signal },
        );
      } catch (e) {
        if (isAbortError(e)) throw e;
        console.error(e);
        showError(t('memories', 'Error while moving {basename}', p));
        failures++;
      } finally {
        if (!signal.aborted) processing.value++;
      }
    });
    for await (const _ of dav.runInParallel(calls, 10, { signal })) {
      // nothing to do
    }
    signal.throwIfAborted();

    if (failures === 0) await done(face, newName);
  } catch (error) {
    if (isAbortError(error)) return;
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
