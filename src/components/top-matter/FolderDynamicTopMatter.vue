<template>
  <FolderGrid v-if="show" :items="folders" />
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import FolderGrid from './FolderGrid.vue';

import { config } from '@services/user-config';
import * as utils from '@services/utils/common';
import { API } from '@services/API';

import type { IFolder } from '@typings';

defineOptions({
  name: 'FolderDynamicTopMatter',
});

const route = useRoute();

const folders = ref<IFolder[]>([]);
const currentFolder = ref('<none>');

const show = computed(() => {
  return folders.value.length && !route.query.recursive;
});

function folder(): string {
  return utils.getFolderRoutePath(config.folders_path);
}

async function refresh(): Promise<boolean> {
  const folderVal = folder();

  // Clear folders if switching to a different folder, otherwise just refresh
  if (currentFolder.value !== folderVal) {
    currentFolder.value = folderVal;
    folders.value = [];
  }

  // Get subfolders URL
  const url = API.Q(API.FOLDERS_SUB(), { folder: folderVal });

  // Make API call to get subfolders
  try {
    const data = (await axios.get<IFolder[]>(url)).data;
    if (folderVal !== folder()) {
      return false;
    }
    folders.value = data;
  } catch (e) {
    console.error(e);
    return false;
  }

  // Filter out hidden folders
  if (!config.show_hidden_folders) {
    folders.value = folders.value.filter((f) => !f.name.startsWith('.') && f.previews?.length);
  }

  // Sort folders by name, case insensitive and natural
  folders.value.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));

  return folders.value.length > 0;
}

defineExpose({ refresh });
</script>
