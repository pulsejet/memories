<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="normal" :can-close="pane === 0">
    <template #title>
      {{ n('memories', 'Upload {n} file', 'Upload {n} files', fileCount, { n: fileCount }) }}
    </template>

    <div class="inner">
      <template v-if="pane === 0">
        <div>
          <NcTextField
            :label="t('memories', 'Destination path')"
            :label-visible="true"
            v-model="uploadPath"
            @click="chooseUploadPath"
            readonly
          />
        </div>

        <div class="options">
          <NcCheckboxRadioSwitch
            v-if="config.albums_enabled"
            :model-value="albums.length > 0"
            :disabled="processing"
            @update:model-value="pane = 1"
          >
            {{ t('memories', 'Add to albums') }}
            <br />
            <span class="switch-subtitle">{{ albumNames }}</span>
          </NcCheckboxRadioSwitch>

          <NcCheckboxRadioSwitch v-model="tagsShown" :disabled="processing">
            {{ t('memories', 'Add tags') }}
            <br />
            <span class="switch-subtitle">
              {{ t('memories', 'Attach collaborative tags to all uploads') }}
            </span>
          </NcCheckboxRadioSwitch>

          <div class="tags-pane">
            <EditTags v-if="tagsShown" ref="tags" :photos="[]" :disabled="processing" />
          </div>
        </div>

        <div class="actions">
          <div class="progress-bar" v-if="processing">
            {{ progressNote }}
            <NcProgressBar :value="progress" :error="true" />
          </div>
          <NcButton @click="upload" variant="primary" :disabled="processing">
            {{ t('memories', 'Upload') }}
          </NcButton>
        </div>
      </template>

      <template v-else-if="pane === 1">
        <AlbumPicker :photos="[]" :initial-selection="albums" :disabled="processing" @select="selectAlbums" />
      </template>
    </div>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef, createApp, defineAsyncComponent } from 'vue';
import { useRouter } from 'vue-router';

import Modal from '@components/modal/Modal.vue';
import AlbumPicker from '@components/modal/AlbumPicker.vue';
import EditTags from '@components/modal/EditTags.vue';
import UploadMenuItem from '@components/header/UploadMenuItem.vue';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import axios from '@nextcloud/axios';
import { getUploader } from '@nextcloud/upload';
import { showError } from '@nextcloud/dialogs';

import { useModal } from '@services/modal';
import { t, n } from '@services/l10n';
import { config } from '@services/user-config';
import { useRouteIsFolders, useRouteIsPublic } from '@services/route-checker';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { API } from '@services/API';
import { registerGlobals } from '../../bootstrap';
import { registerRouteCheckers } from '../../router';

import type { IAlbum, IPhoto, IUploadNativeX } from '@typings';
import type PCancelable from 'p-cancelable';

defineOptions({
  name: 'UploadModal',
});

const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const routeIsFolders = useRouteIsFolders();
const routeIsPublic = useRouteIsPublic();
const tags = useTemplateRef<InstanceType<typeof EditTags>>('tags');

const files = ref<File[]>([]);
const pane = ref<0 | 1>(0);
const uploadPath = ref('/');
const albums = ref<IAlbum[]>([]);
const tagsShown = ref(false);
const processing = ref(false);
const progress = ref(0);
const progressNote = ref(String());
const currentUpload = ref<null | PCancelable<any>>(null);
const locals = ref<IUploadNativeX[]>([]);

const fileCount = computed(() => files.value.length + locals.value.length);

const albumNames = computed(() => {
  if (!albums.value.length) {
    return t('memories', 'No albums selected');
  }

  return albums.value.map((album) => album.name).join(', ');
});

console.assert(!_m.modals.upload, 'UploadModal created twice');
_m.modals.upload = open;

// create right header button
const header = document.querySelector<HTMLDivElement>('.header-right, .header-end');
if (header && utils.uid) {
  const div = document.createElement('div');
  header.prepend(div);
  const headerApp = createApp(UploadMenuItem);
  // Share globals and router with header button
  registerGlobals(headerApp);
  registerRouteCheckers(headerApp);
  try {
    headerApp.use(router);
  } catch {}
  headerApp.mount(div);
}

function open(localsIn?: IUploadNativeX[]) {
  // cannot upload to public shares
  if (routeIsPublic.value) return;

  // Upload local files natively (NativeX)
  if (localsIn?.length) {
    resetState();
    locals.value = localsIn;
    show.value = true;
    return;
  }

  // reset everything
  resetState();

  // prompt the user to select the files
  const input = document.createElement('input');
  input.type = 'file';
  input.multiple = true;
  input.accept = 'image/*,image/heic,/image/tiff,video/*';
  input.addEventListener('cancel', () => input.remove());
  input.addEventListener('change', () => {
    files.value = Array.from(input.files ?? []);
    show.value = !!files.value.length;
    input.remove();
  });
  input.click();
}

function resetState() {
  pane.value = 0;
  files.value = [];
  albums.value = [];
  tagsShown.value = false;
  processing.value = false;
  progress.value = 0;
  progressNote.value = String();
  locals.value = [];

  // choose first path of timeline path
  uploadPath.value = config.timeline_path.split(';')?.[0] ?? '/';

  // choose current folder if in folders view
  if (routeIsFolders.value) {
    uploadPath.value = utils.getFolderRoutePath(config.folders_path);
  }
}

function cleanup() {
  show.value = false;
  files.value = [];
  locals.value = [];
  processing.value = false;
  currentUpload.value?.cancel('Modal closed');
}

async function chooseUploadPath() {
  uploadPath.value =
    (await utils.chooseNcFolder(t('memories', 'Choose the destination folder for the upload'), uploadPath.value)) ||
    uploadPath.value;
}

function selectAlbums(selection: IAlbum[]) {
  albums.value = selection;
  pane.value = 0;
}

async function upload() {
  try {
    processing.value = true;
    progress.value = 0;
    await uploadI();
    close();
  } catch {
    // do not quit
  } finally {
    processing.value = false;
    progressNote.value = String();
  }
}

async function uploadI() {
  // Tags may be created which might throw
  let tagsArr: number[] = [];
  if (tagsShown.value) {
    try {
      progressNote.value = t('memories', 'Creating tags');
      tagsArr = (await tags.value?.result?.())?.add ?? [];
    } catch (e: any) {
      showError(e);
      console.error(e);
      throw e;
    }
  }

  type UploadSource = { kind: 'file'; file: File } | { kind: 'nativex'; entry: IUploadNativeX };
  const queue: UploadSource[] = [
    ...files.value.map((file) => ({ kind: 'file' as const, file })),
    ...locals.value.map((entry) => ({ kind: 'nativex' as const, entry })),
  ];

  /**
   * for each file:
   *   upload
   *   for each album:
   *     add to album
   *   attach tags
   * Each operation is 100KB overhead
   */
  const OP_FAC = 100 * 1024;
  let maxProgress = files.value.reduce((sum, file) => sum + file.size, 0); // file size
  maxProgress += locals.value.length; // local size unknown, assume 1 each
  maxProgress += (tagsArr.length ? 1 : 0) * queue.length * OP_FAC; // tags
  maxProgress += albums.value.length * queue.length * OP_FAC; // albums

  // Update progress bar
  let progressLocal = 0;
  const addProgress = (delta: number) => {
    progressLocal += delta;
    progress.value = maxProgress ? (progressLocal * 100) / maxProgress : 0;
  };

  // Guard against closed modal
  const guardOpen = () => {
    if (!show.value) throw new Error('Modal closed');
  };

  // List of successful uploads
  const uploaded = [] as {
    fileid: number;
    filename: string;
    name: string;
  }[];

  // Sources that still need (re)trying after a partial failure
  const remaining = [] as UploadSource[];

  // Start upload process
  const uploader = getUploader();
  for (const source of queue) {
    guardOpen();

    // add slash to upload path
    let path = uploadPath.value;
    if (!path.endsWith('/')) path += '/';

    if (source.kind === 'nativex') {
      // NativeX files upload natively without downloading
      try {
        const dest = path + source.entry.filename;
        progressNote.value = t('memories', 'Uploading {file}', { file: source.entry.filename });
        const fileid = await uploadNativeX(source.entry.auid, dest);
        guardOpen();
        uploaded.push({ fileid, filename: dest, name: source.entry.filename });
      } catch (e) {
        showError(t('memories', 'Failed to upload {file}', { file: source.entry.filename }));
        console.error(e);
        remaining.push(source);
      } finally {
        addProgress(1);
      }
    } else {
      // Browser files are uploaded using the uploader.
      const file = source.file;
      try {
        progressNote.value = t('memories', 'Uploading {file}', { file: file.name });

        const filename = path + file.name;
        const promise = (currentUpload.value = uploader.upload(filename, file));
        const res = await promise;
        currentUpload.value = null;

        const fileid = parseInt(res.response?.headers?.['oc-fileid'] ?? 0);
        if (!fileid) throw new Error('No fileid header in response');

        uploaded.push({ fileid, filename, name: file.name });
      } catch (e) {
        showError(t('memories', 'Failed to upload {file}', { file: file.name }));
        console.error(e);
        remaining.push(source);
      } finally {
        currentUpload.value = null;
        addProgress(file.size);
      }
    }
  }

  // Make IPhoto types for album calls
  const photos = uploaded.map((f) => ({
    fileid: f.fileid,
    basename: f.name,
    imageInfo: {
      filename: f.filename, // prevent info calls (see dav/base.ts)
    },
  })) as IPhoto[];

  // Add files to albums
  for (const album of albums.value) {
    guardOpen();

    progressNote.value = t('memories', 'Adding files to album {album}', { album: album.name });
    for await (const fileIds of dav.addToAlbum(album.user, album.name, photos)) {
      addProgress(OP_FAC);
    }
  }

  // Attach tags
  if (tagsArr.length) {
    for (const photo of photos) {
      guardOpen();

      progressNote.value = t('memories', 'Attaching tags to {file}', { file: photo.basename! });
      try {
        await axios.patch<null>(API.TAG_SET(photo.fileid), { add: tagsArr });
      } catch (e) {
        showError(t('memories', 'Failed to attach tags to {file}', { file: photo.basename! }));
        console.error(e);
      } finally {
        addProgress(OP_FAC);
      }
    }
  }

  // Refresh if anything was uploaded
  if (uploaded.length) {
    utils.bus.emit('memories:timeline:soft-refresh', null);
  }

  // Throw if all files were not uploaded
  if (uploaded.length !== queue.length) {
    showError(t('memories', 'Some files have not been uploaded.'));
    files.value = remaining.filter((s): s is { kind: 'file'; file: File } => s.kind === 'file').map((s) => s.file);
    locals.value = remaining
      .filter((s): s is { kind: 'nativex'; entry: IUploadNativeX } => s.kind === 'nativex')
      .map((s) => s.entry);
    throw new Error('Some files have not been uploaded.');
  }
}

async function uploadNativeX(auid: string, filename: string): Promise<number> {
  const res = await fetch(nativex.NAPI.UPLOAD_LOCAL(auid, filename));
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const fileid = parseInt((await res.json())?.fileid ?? 0);
  if (!fileid) throw new Error('No fileid in response');
  return fileid;
}
</script>

<style lang="scss" scoped>
.inner {
  margin-top: 1em;

  :deep(.checkbox-content) {
    max-width: calc(100% - 20px);
    padding: 4px 10px;
  }

  :deep(.checkbox-content__text) {
    display: block;
    line-height: 1.1em;
  }
}

.switch-subtitle {
  font-size: 0.82em;
}

.options {
  margin-top: 10px;

  .tags-pane {
    :deep(.outer) {
      margin-top: 2px;
      margin-left: 28px;
      margin-right: 14px;
    }
  }
}

.actions {
  display: flex;
  justify-content: flex-end;
  padding: 0.5rem 0 0;
  flex-direction: column;
  align-items: flex-end;

  .progress-bar {
    width: 100%;
  }
}
</style>
