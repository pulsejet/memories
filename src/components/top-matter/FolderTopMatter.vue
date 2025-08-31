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
      isUploading: false,
      uploadProgress: 0,
      uploadStatus: '',
      uploadCount: 0,
      uploadFailures: 0,
    };
  },

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
  },

  methods: {
    share() {
      _m.modals.shareNodeLink(utils.getFolderRoutePath(this.config.folders_path));
    },

    upload() {
      _m.modals.upload();
    },

    toggleRecursive() {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

    getRoute(path: string[]) {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    // --- NEW UPLOAD LOGIC ---
    triggerFileUpload() {
      (this.$refs.fileInput as HTMLInputElement).click();
    },

    handleFileSelection(event: Event) {
      const target = event.target as HTMLInputElement;
      const files = target.files;
      if (!files || files.length === 0) {
        return;
      }

      this.uploadCount = files.length;
      this.uploadFailures = 0;
      this.isUploading = true;
      this.uploadProgress = 0;

      for (const file of files) {
        this.uploadFile(file);
      }

      target.value = '';
    },

    uploadFile(file: File) {
      const token = this.$route.params.token as string;
      const url = generateUrl(`/apps/memories/s/${token}/upload`);

      const formData = new FormData();
      formData.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);

      this.uploadStatus = t('memories', 'Uploading {file}...', { file: file.name });

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          this.uploadProgress = (e.loaded / e.total) * 100;
        }
      };

      const onFinish = (success: boolean) => {
        if (!success) {
            this.uploadFailures++;
        }
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
            this.uploadStatus = t('memories', 'Upload of {file} failed: {error}', { file: file.name, error: response.error || xhr.statusText });
          } catch (e) {
            this.uploadStatus = t('memories', 'Upload of {file} failed: {error}', { file: file.name, error: xhr.statusText });
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
    // --- END OF NEW UPLOAD LOGIC ---
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  .breadcrumb {
    min-width: 0;
    height: unset;
    .share-name {
      margin-left: 0.75em;
    }
  }
}

.upload-progress {
  grid-column: 1 / -1;
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
}

.upload-progress progress {
  flex-grow: 1;
}
</style>

