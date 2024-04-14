<template>
  <FolderGrid v-if="show" :items="folders" />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserMixin from '@mixins/UserConfig';

import axios from '@nextcloud/axios';
import { getLanguage } from '@nextcloud/l10n';

import FolderGrid from './FolderGrid.vue';

import * as utils from '@services/utils';
import { API } from '@services/API';

import type { IFolder } from '@typings';

export default defineComponent({
  name: 'FolderDynamicTopMatter',

  data: () => ({
    folders: [] as IFolder[],
    currentFolder: '<none>',
  }),

  components: {
    FolderGrid,
  },

  mixins: [UserMixin],

  computed: {
    show() {
      return this.folders.length && !this.$route.query.recursive;
    },
  },

  methods: {
    async refresh(): Promise<boolean> {
      const folder = utils.getFolderRoutePath(this.config.folders_path);

      // Clear folders if switching to a different folder, otherwise just refresh
      if (this.currentFolder === folder) {
        this.currentFolder = folder;
        this.folders = [];
      }

      // Get subfolders URL
      const url = API.Q(API.FOLDERS_SUB(), { folder });

      // Make API call to get subfolders
      try {
        this.folders = (await axios.get<IFolder[]>(url)).data;
      } catch (e) {
        console.error(e);
        return false;
      }

      // Filter out hidden folders
      if (!this.config.show_hidden_folders) {
        this.folders = this.folders.filter((f) => !f.name.startsWith('.') && f.previews?.length);
      }

      // Sort folders by name, case insensitive and natural
      this.folders.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));

      return this.folders.length > 0;
    },
  },
});
</script>
