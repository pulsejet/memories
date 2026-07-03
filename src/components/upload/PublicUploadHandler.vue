<template>
  <div v-if="processing" class="upload-progress-bar">
    <span class="progress-text">{{ progressNote }}</span>
    <NcProgressBar :value="progress" :error="hasError" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar.js');

import { Uploader } from '@nextcloud/upload';
import { Folder, Permission } from '@nextcloud/files';
import { showError, showSuccess } from '@nextcloud/dialogs';

import * as utils from '@services/utils';
import { createClient, type FileStat } from 'webdav';

export default defineComponent({
  name: 'PublicUploadHandler',
  components: {
    NcProgressBar,
  },

  data: () => ({
    processing: false,
    progress: 0,
    progressNote: String(),
    hasError: false,
    currentUploads: [] as { cancel(): void }[],
    existingFiles: new Set<string>(), // Track existing files in current directory
  }),

  beforeDestroy() {
    this.cancelAllUploads();
  },

  computed: {
    canUpload(): boolean {
      return this.routeIsPublic && this.initstate.allow_upload === true;
    },

    /**
     * Get the base URL including any subdirectory where Nextcloud is installed
     */
    baseUrl(): string {
      const webroot = (window as any).OC?.webroot || '';
      return window.location.origin + webroot;
    },
  },

  methods: {
    /**
     * Initiates the file upload process by opening the file picker
     */
    startUpload() {
      if (!this.canUpload) {
        showError(this.t('memories', 'Upload not permitted'));
        return;
      }

      const input = document.createElement('input');
      input.type = 'file';
      input.multiple = true;
      input.accept = 'image/*,image/heic,image/tiff,video/*';

      input.addEventListener('cancel', () => input.remove());
      input.addEventListener('change', async () => {
        const files = Array.from(input.files ?? []);
        if (files.length > 0) {
          await this.uploadFiles(files);
        }
        input.remove();
      });

      input.click();
    },

    /**
     * Fetches existing files in the current directory to check for duplicates
     * @returns Set of filenames that already exist
     */
    async fetchExistingFiles(): Promise<Set<string>> {
      try {
        const token = this.$route.params.token;
        const uploadPath = this.getCurrentPath();
        const publicDavPath = `${this.baseUrl}/public.php/dav/files/${token}${uploadPath}`;

        const client = createClient(publicDavPath);
        const contents = (await client.getDirectoryContents('/', { details: false })) as Array<FileStat>;

        return new Set(contents.map((item) => item.basename));
      } catch (error) {
        console.error('Failed to fetch existing files:', error);
        return new Set();
      }
    },

    /**
     * Uploads multiple files to the current directory
     * Skips files that already exist to prevent overwriting
     * @param files Array of files to upload
     */
    async uploadFiles(files: File[]) {
      try {
        this.processing = true;
        this.progress = 0;
        this.hasError = false;

        // Fetch existing files to check for duplicates
        this.existingFiles = await this.fetchExistingFiles();

        // Filter out files that already exist
        const filesToUpload = files.filter((file) => !this.existingFiles.has(file.name));
        const skippedFiles = files.filter((file) => this.existingFiles.has(file.name));

        if (skippedFiles.length > 0) {
          showError(
            this.n(
              'memories',
              'Skipped {n} file that already exists',
              'Skipped {n} files that already exist',
              skippedFiles.length,
              { n: skippedFiles.length },
            ),
          );
        }

        if (filesToUpload.length === 0) {
          this.processing = false;
          return;
        }

        // Setup WebDAV destination
        const token = this.$route.params.token as string;
        const currentPath = this.getCurrentPath();

        // Build the absolute URL properly
        const protocol = window.location.protocol;
        const host = window.location.host;
        const webroot = (window as any).OC?.webroot || '';

        // Construct the full URL ensuring it's absolute
        const baseURL = `${protocol}//${host}${webroot}`;
        const davPath = '/public.php/dav';
        const rootPath = `/files/${token}`;
        const fullPath = `${rootPath}${currentPath}`;

        // The source must be a complete URL
        const folderSource = `${baseURL}${davPath}${fullPath}`;

        const destination = new Folder({
          id: 0,
          source: folderSource,
          root: rootPath,
          owner: null,
          permissions: Permission.CREATE,
        });
        const uploader = new Uploader(true, destination);

        // Track upload progress
        const totalSize = filesToUpload.reduce((sum, file) => sum + file.size, 0);
        let uploadedSize = 0;

        const successful: string[] = [];
        const failed: string[] = [];

        // Upload each file sequentially
        for (const [index, file] of filesToUpload.entries()) {
          if (!this.processing) break;

          try {
            this.progressNote = this.t('memories', 'Uploading {file} ({current}/{total})', {
              file: file.name,
              current: index + 1,
              total: filesToUpload.length,
            });

            const uploadPromise = uploader.upload(file.name, file);
            this.currentUploads.push(uploadPromise);
            await uploadPromise;

            successful.push(file.name);
            uploadedSize += file.size;
            this.progress = (uploadedSize / totalSize) * 100;
          } catch (error) {
            console.error('Upload failed for file:', file.name, error);
            failed.push(file.name);
            this.hasError = true;

            uploadedSize += file.size;
            this.progress = (uploadedSize / totalSize) * 100;
          }
        }

        this.showUploadResults(successful, failed);

        // Refresh timeline to show new uploads
        if (successful.length > 0) {
          utils.bus.emit('memories:timeline:soft-refresh', null);
        }
      } catch (error) {
        console.error('Upload process failed:', error);
        showError(this.t('memories', 'Upload failed'));
        this.hasError = true;
      } finally {
        // Clear upload state after showing results briefly
        setTimeout(() => {
          this.processing = false;
          this.progress = 0;
          this.progressNote = String();
          this.hasError = false;
          this.currentUploads = [];
        }, 1000);
      }
    },

    /**
     * Displays upload results to the user
     * @param successful Array of successfully uploaded filenames
     * @param failed Array of failed upload filenames
     */
    showUploadResults(successful: string[], failed: string[]) {
      if (successful.length > 0 && failed.length === 0) {
        showSuccess(
          this.n('memories', 'Successfully uploaded {n} file', 'Successfully uploaded {n} files', successful.length, {
            n: successful.length,
          }),
        );
      } else if (successful.length > 0 && failed.length > 0) {
        showError(
          this.t('memories', 'Uploaded {success} files, {failed} failed', {
            success: successful.length,
            failed: failed.length,
          }),
        );
      } else if (failed.length > 0) {
        showError(
          this.n('memories', 'Failed to upload {n} file', 'Failed to upload {n} files', failed.length, {
            n: failed.length,
          }),
        );
      }
    },

    /**
     * Resolves the current upload path from route parameters
     * @returns Normalized path string (e.g., "/" or "/subfolder")
     */
    getCurrentPath(): string {
      const routePath = this.$route.params.path || String();
      if (Array.isArray(routePath)) {
        return '/' + (routePath.join('/') || String());
      } else if (typeof routePath === 'string') {
        return '/' + (routePath || String());
      } else {
        return '/';
      }
    },

    /**
     * Cancels all ongoing uploads and resets state
     */
    cancelAllUploads() {
      this.currentUploads.forEach((upload) => {
        try {
          upload.cancel();
        } catch (error) {}
      });
      this.currentUploads = [];
      this.processing = false;
    },
  },
});
</script>

<style lang="scss" scoped>
.upload-progress-bar {
  min-width: 200px;
  max-width: 300px;

  .progress-text {
    display: block;
    font-size: 0.8em;
    color: var(--color-text-lighter);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  :deep(.progress-bar) {
    height: 4px;
  }
}
</style>
