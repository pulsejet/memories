<template>
  <div class="top-matter">
    <NcBreadcrumbs :key="$route.path">
      <NcBreadcrumb :name="rootFolderName" :to="getRoute([])" :force-icon-text="routeIsPublic">
        <template #icon>
          <ShareIcon v-if="routeIsPublic" :size="20" />
          <HomeIcon v-else :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="folder in list" :key="folder.idx" :name="folder.text" :to="getRoute(folder.path)" />
    </NcBreadcrumbs>

    <div class="right-actions">
      <NcActions :inline="3">
        <NcActionButton
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Share folder')"
          @click="share()"
          close-after-click
        >
          {{ t('memories', 'Share folder') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Upload files')"
          @click="upload()"
          close-after-click
        >
          {{ t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <!-- Public upload button -->
        <NcActionButton
          v-if="routeIsPublic && allowUpload"
          :aria-label="t('memories', 'Upload files')"
          :disabled="isUploading"
          @click="triggerFileUpload"
        >
          {{ isUploading ? t('memories', 'Uploading...') : t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton @click="toggleRecursive" close-after-click>
          {{ recursive ? t('memories', 'Folder view') : t('memories', 'Timeline view') }}
          <template #icon>
            <FoldersIcon v-if="recursive" :size="20" />
            <TimelineIcon v-else :size="20" />
          </template>
        </NcActionButton>
      </NcActions>

      <!-- Progress bar for PublicUploadHandler -->
      <PublicUploadHandler ref="uploadHandler" v-if="routeIsPublic && allowUpload" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';

import * as utils from '@services/utils';
import * as nativex from '@native';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';

export default defineComponent({
  name: 'FolderTopMatter',

  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    PublicUploadHandler,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
    UploadIcon,
  },

  mixins: [UserConfig],

  computed: {
    list(): {
      text: string;
      path: string[];
      idx: number;
    }[] {
      let path: string[] | string = this.$route.params.path || '';
      if (typeof path === 'string') {
        path = path.split('/');
      }

      return path
        .filter(Boolean) // non-empty
        .map((text, idx, arr) => {
          const path = arr.slice(0, idx + 1);
          return { text, path, idx };
        });
    },

    recursive(): boolean {
      return !!this.$route.query.recursive;
    },

    rootFolderName(): string {
      return this.routeIsPublic ? this.initstate.shareTitle : this.t('memories', 'Home');
    },

    isNative(): boolean {
      return nativex.has();
    },

    allowUpload(): boolean {
      return this.initstate.allow_upload === true;
    },

    // Check if PublicUploadHandler is currently uploading
    isUploading(): boolean {
      const handler = this.$refs.uploadHandler as any;
      return handler?.processing || false;
    },
  },

  methods: {
    share(): void {
      _m.modals.shareNodeLink(utils.getFolderRoutePath(this.config.folders_path));
    },

    upload(): void {
      _m.modals.upload();
    },

    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    // Trigger upload via PublicUploadHandler
    triggerFileUpload(): void {
      const handler = this.$refs.uploadHandler as any;
      if (handler && typeof handler.startUpload === 'function') {
        handler.startUpload();
      } else {
        console.error('PublicUploadHandler not properly initialized');
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px; // Add spacing between actions and progress bar
  }
}
</style>
