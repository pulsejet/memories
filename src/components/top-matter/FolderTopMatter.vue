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
      <NcActions :inline="1">
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

        <!-- NEW PUBLIC UPLOAD BUTTON -->
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
    </div>

    <!-- Hidden file input that we trigger programmatically -->
    <input ref="fileInput" type="file" style="display: none" multiple @change="handleFileSelection" />

    <!-- NEW UPLOAD PROGRESS DISPLAY -->
    <div v-if="isUploading" class="upload-progress">
      <progress :value="uploadProgress" max="100"></progress>
      <span>{{ uploadStatus }}</span>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { generateUrl } from '@nextcloud/router';
import { t } from '@nextcloud/l10n';

import UserConfig from '@mixins/UserConfig';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

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
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
    UploadIcon,
  },

  mixins: [UserConfig],

  data() {
    return {
      isUploading: false as boolean,
      uploadProgress: 0 as number,
      uploadStatus: '' as string,
      uploadCount: 0 as number,
      uploadFailures: 0 as number,
    };
  },

  computed: {
    list(): { text: string; path: string[]; idx: number }[] {
      let path: string[] | string = this.$route.params.path || '';
      if (typeof path === 'string') path = path.split('/');

      return path
        .filter(Boolean)
        .map((text, idx, arr) => ({ text, path: arr.slice(0, idx + 1), idx }));
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
      // allow uploads only for editable folder shares
      return this.routeIsPublic && this.initstate.shareType === 'folder' && !this.initstate.noDownload;
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

    triggerFileUpload(): void {
      (this.$refs.fileInput as HTMLInputElement).click();
    },

    handleFileSelection(this: any, event: Event): void {
      const target = event.target as HTMLInputElement;
      const files = target.files;
      if (!files || files.length === 0) return;

      this.uploadCount = files.length;
      this.uploadFailures = 0;
      this.isUploading = true;
      this.uploadProgress = 0;

      for (const file of Array.from(files)) {
        this.uploadFile(file); // TS now sees uploadFile because of this: any
      }

      target.value = '';
    },

    uploadFile(this: any, file: File): void {
      const token = this.$route.params.token as string;
      const url = generateUrl(`/apps/memories/s/${token}/upload`);
      const formData = new FormData();
      formData.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);

      this.uploadStatus = t('memories', 'Uploading {file}...', { file: file.name });

      xhr.upload.onprogress = (e: ProgressEvent) => {
        if (e.lengthComputable) this.uploadProgress = (e.loaded / e.total) * 100;
      };

      const onFinish = (success: boolean) => {
        if (!success) this.uploadFailures++;
        this.uploadCount--;

        if (this.uploadCount <= 0) {
          this.isUploading = false;
          this.uploadProgress = 0;

          if (this.uploadFailures > 0) {
            this.uploadStatus = t('memories', '{count} files failed to upload.', { count: this.uploadFailures });
          } else {
            this.uploadStatus = t('memories', 'All files uploaded successfully.');
            window.location.reload();
          }
        }
      };

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          onFinish(true);
        } else {
          try {
            const response = JSON.parse(xhr.responseText);
            this.uploadStatus = t('memories', 'Upload of {file} failed: {error}', {
              file: file.name,
              error: response.error || xhr.statusText,
            });
          } catch {
            this.uploadStatus = t('memories', 'Upload of {file} failed: {error}', {
              file: file.name,
              error: xhr.statusText,
            });
          }
          onFinish(false);
        }
      };

      xhr.onerror = () => {
        this.uploadStatus = t('memories', 'An error occurred during the upload of {file}', { file: file.name });
        onFinish(false);
      };

      xhr.send(formData);
    },
  },
});
</script>
