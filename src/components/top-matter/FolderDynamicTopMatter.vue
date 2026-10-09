<template>
  <FolderGrid v-if="isReady && isContent && folders" :items="folders" />
</template>

<script setup lang="ts">
import { computed, ref, shallowRef, onMounted } from 'vue';
import { useRoute } from 'vue-router';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import FolderGrid from './FolderGrid.vue';

import { config } from '@services/user-config';
import { useRouteState } from '@services/route-state';
import { API } from '@services/API';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as utils from '@services/utils/common';

import type { IFolder } from '@typings';

defineOptions({
  name: 'FolderDynamicTopMatter',
});

const emit = defineEmits<{
  load: [];
}>();

const route = useRoute();

const folders = shallowRef<IFolder[] | null>(null);
const currentFolder = ref('<none>');
const abort = useAbort();

useRouteState({
  state: { folders, currentFolder },
});

const isReady = computed((): boolean => folders.value !== null);
const isContent = computed((): boolean => !!folders.value?.length && !route.query.recursive);

function folder(): string {
  return utils.getFolderRoutePath(config.folders_path);
}

async function refresh(): Promise<void> {
  const folderVal = folder();

  // Clear folders if switching to a different folder, otherwise just refresh
  if (currentFolder.value !== folderVal) {
    currentFolder.value = folderVal;
    folders.value = null;
  }

  // Create an abort signal for this request.
  const signal = abort.renew();

  // Get subfolders URL
  const url = API.Q(API.FOLDERS_SUB(), { folder: folderVal });

  // Make API call to get subfolders
  try {
    const data = (await axios.get<IFolder[]>(url, { signal })).data;
    signal.throwIfAborted();
    folders.value = data;
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
    return;
  } finally {
    if (!signal.aborted) {
      folders.value ??= [];
    }
  }

  // Filter out hidden folders
  if (!config.show_hidden_folders) {
    folders.value = folders.value.filter((f) => !f.name.startsWith('.') && f.previews?.length);
  }

  // Sort folders by name, case insensitive and natural
  folders.value.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));

  emit('load');
}

onMounted(refresh);

defineExpose({ isReady, isContent, refresh });
</script>
