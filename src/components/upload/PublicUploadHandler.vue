<template>
  <div v-if="processing" class="upload-progress-bar">
    <span class="progress-text">{{ progressNote }}</span>
    <NcProgressBar :value="progress" :error="hasError" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import { Uploader } from '@nextcloud/upload';
import { Folder, Permission } from '@nextcloud/files';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { routeIs } from '@services/router';
import { t, n } from '@services/l10n';
import * as utils from '@services/utils/common';
import initstate from '@services/init-state';
import { createClient, type FileStat } from 'webdav';

const route = useRoute();
const processing = ref(false);
const progress = ref(0);
const progressNote = ref(String());
const hasError = ref(false);
const currentUploads = ref<{ cancel(): void }[]>([]);
const existingFiles = ref(new Set<string>()); // Track existing files in current directory

onBeforeUnmount(() => {
  cancelAllUploads();
});

const canUpload = computed((): boolean => {
  return routeIs.Public && initstate.allow_upload === true;
});

/**
 * Get the base URL including any subdirectory where Nextcloud is installed
 */
const baseUrl = computed((): string => {
  const webroot = (window as any).OC?.webroot || '';
  return window.location.origin + webroot;
});

/**
 * Initiates the file upload process by opening the file picker
 */
function startUpload() {
  if (!canUpload.value) {
    showError(t('memories', 'Upload not permitted'));
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
      await uploadFiles(files);
    }
    input.remove();
  });

  input.click();
}

/**
 * Fetches existing files in the current directory to check for duplicates
 * @returns Set of filenames that already exist
 */
async function fetchExistingFiles(): Promise<Set<string>> {
  try {
    const token = route.params.token?.toString();
    const uploadPath = getCurrentPath();
    const publicDavPath = `${baseUrl.value}/public.php/dav/files/${token}${uploadPath}`;

    const client = createClient(publicDavPath);
    const contents = (await client.getDirectoryContents('/', { details: false })) as Array<FileStat>;

    return new Set(contents.map((item) => item.basename));
  } catch (error) {
    console.error('Failed to fetch existing files:', error);
    return new Set();
  }
}

/**
 * Uploads multiple files to the current directory
 * Skips files that already exist to prevent overwriting
 * @param files Array of files to upload
 */
async function uploadFiles(files: File[]) {
  try {
    processing.value = true;
    progress.value = 0;
    hasError.value = false;

    // Fetch existing files to check for duplicates
    existingFiles.value = await fetchExistingFiles();

    // Filter out files that already exist
    const filesToUpload = files.filter((file) => !existingFiles.value.has(file.name));
    const skippedFiles = files.filter((file) => existingFiles.value.has(file.name));

    if (skippedFiles.length > 0) {
      showError(
        n(
          'memories',
          'Skipped {n} file that already exists',
          'Skipped {n} files that already exist',
          skippedFiles.length,
          { n: skippedFiles.length },
        ),
      );
    }

    if (filesToUpload.length === 0) {
      processing.value = false;
      return;
    }

    // Setup WebDAV destination
    const token = route.params.token?.toString();
    const currentPath = getCurrentPath();

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
    // @nextcloud/upload bundles its own copy of @nextcloud/files,
    // so its Folder type differs from ours despite identical shape.
    const uploader = new Uploader(true, <any>destination);

    // Track upload progress
    const totalSize = filesToUpload.reduce((sum, file) => sum + file.size, 0);
    let uploadedSize = 0;

    const successful: string[] = [];
    const failed: string[] = [];

    // Upload each file sequentially
    for (const [index, file] of filesToUpload.entries()) {
      if (!processing.value) break;

      try {
        progressNote.value = t('memories', 'Uploading {file} ({current}/{total})', {
          file: file.name,
          current: index + 1,
          total: filesToUpload.length,
        });

        const uploadPromise = uploader.upload(file.name, file);
        currentUploads.value.push(uploadPromise);
        await uploadPromise;

        successful.push(file.name);
        uploadedSize += file.size;
        progress.value = (uploadedSize / totalSize) * 100;
      } catch (error) {
        console.error('Upload failed for file:', file.name, error);
        failed.push(file.name);
        hasError.value = true;

        uploadedSize += file.size;
        progress.value = (uploadedSize / totalSize) * 100;
      }
    }

    showUploadResults(successful, failed);

    // Refresh timeline to show new uploads
    if (successful.length > 0) {
      utils.bus.emit('memories:timeline:soft-refresh', null);
    }
  } catch (error) {
    console.error('Upload process failed:', error);
    showError(t('memories', 'Upload failed'));
    hasError.value = true;
  } finally {
    // Clear upload state after showing results briefly
    setTimeout(() => {
      processing.value = false;
      progress.value = 0;
      progressNote.value = String();
      hasError.value = false;
      currentUploads.value = [];
    }, 1000);
  }
}

/**
 * Displays upload results to the user
 * @param successful Array of successfully uploaded filenames
 * @param failed Array of failed upload filenames
 */
function showUploadResults(successful: string[], failed: string[]) {
  if (successful.length > 0 && failed.length === 0) {
    showSuccess(
      n('memories', 'Successfully uploaded {n} file', 'Successfully uploaded {n} files', successful.length, {
        n: successful.length,
      }),
    );
  } else if (successful.length > 0 && failed.length > 0) {
    showError(
      t('memories', 'Uploaded {success} files, {failed} failed', {
        success: successful.length,
        failed: failed.length,
      }),
    );
  } else if (failed.length > 0) {
    showError(
      n('memories', 'Failed to upload {n} file', 'Failed to upload {n} files', failed.length, {
        n: failed.length,
      }),
    );
  }
}

/**
 * Resolves the current upload path from route parameters
 * @returns Normalized path string (e.g., "/" or "/subfolder")
 */
function getCurrentPath(): string {
  const routePath = route.params.path || String();
  if (Array.isArray(routePath)) {
    return '/' + (routePath.join('/') || String());
  } else if (typeof routePath === 'string') {
    return '/' + (routePath || String());
  } else {
    return '/';
  }
}

/**
 * Cancels all ongoing uploads and resets state
 */
function cancelAllUploads() {
  currentUploads.value.forEach((upload) => {
    try {
      upload.cancel();
    } catch (error) {}
  });
  currentUploads.value = [];
  processing.value = false;
}

defineExpose({
  processing,
  startUpload,
});
</script>

<style lang="scss" scoped>
.upload-progress-bar {
  min-width: 200px;
  max-width: 300px;

  .progress-text {
    display: block;
    font-size: 0.8em;
    color: var(--color-text-maxcontrast);
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
