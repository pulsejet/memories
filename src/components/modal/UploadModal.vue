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

<script lang="ts">
import { createApp, defineComponent, defineAsyncComponent } from 'vue';

import Modal from '@components/modal/Modal.vue';
import ModalMixin from '@components/modal/ModalMixin';
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

import UserConfig from '@mixins/UserConfig';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { API } from '@services/API';
import { registerGlobals } from '../../bootstrap';
import { registerRouteCheckers } from '../../router';

import type { IAlbum, IPhoto, IUploadNativeX } from '@typings';
import type PCancelable from 'p-cancelable';

export default defineComponent({
  name: 'UploadModal',
  components: {
    NcButton,
    NcTextField,
    NcProgressBar,
    NcCheckboxRadioSwitch,
    Modal,
    AlbumPicker,
    EditTags,
  },

  mixins: [ModalMixin, UserConfig],

  data: () => ({
    files: [] as File[],
    pane: 0 as 0 | 1,
    uploadPath: '/',
    albums: [] as IAlbum[],
    tagsShown: false,
    processing: false,
    progress: 0,
    progressNote: String(),
    currentUpload: null as null | PCancelable<any>,
    locals: [] as IUploadNativeX[],
  }),

  created() {
    console.assert(!_m.modals.upload, 'UploadModal created twice');
    _m.modals.upload = this.open;

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
        headerApp.use(this.$router);
      } catch {}
      headerApp.mount(div);
    }
  },

  computed: {
    fileCount(): number {
      return this.files.length + this.locals.length;
    },

    albumNames() {
      if (!this.albums.length) {
        return this.t('memories', 'No albums selected');
      }

      return this.albums.map((album) => album.name).join(', ');
    },
  },

  methods: {
    refs() {
      return this.$refs as {
        tags?: InstanceType<typeof EditTags>;
      };
    },

    open(locals?: IUploadNativeX[]) {
      // cannot upload to public shares
      if (this.routeIsPublic) return;

      // Upload local files natively (NativeX)
      if (locals?.length) {
        this.resetState();
        this.locals = locals;
        this.show = true;
        return;
      }

      // reset everything
      this.resetState();

      // prompt the user to select the files
      const input = document.createElement('input');
      input.type = 'file';
      input.multiple = true;
      input.accept = 'image/*,image/heic,/image/tiff,video/*';
      input.addEventListener('cancel', () => input.remove());
      input.addEventListener('change', () => {
        this.files = Array.from(input.files ?? []);
        this.show = !!this.files.length;
        input.remove();
      });
      input.click();
    },

    resetState() {
      this.pane = 0;
      this.files = [];
      this.albums = [];
      this.tagsShown = false;
      this.processing = false;
      this.progress = 0;
      this.progressNote = String();
      this.locals = [];

      // choose first path of timeline path
      this.uploadPath = this.config.timeline_path.split(';')?.[0] ?? '/';

      // choose current folder if in folders view
      if (this.routeIsFolders) {
        this.uploadPath = utils.getFolderRoutePath(this.config.folders_path);
      }
    },

    cleanup() {
      this.show = false;
      this.files = [];
      this.locals = [];
      this.processing = false;
      this.currentUpload?.cancel('Modal closed');
    },

    async chooseUploadPath() {
      this.uploadPath =
        (await utils.chooseNcFolder(
          this.t('memories', 'Choose the destination folder for the upload'),
          this.uploadPath,
        )) || this.uploadPath;
    },

    selectAlbums(selection: IAlbum[]) {
      this.albums = selection;
      this.pane = 0;
    },

    async upload() {
      try {
        this.processing = true;
        this.progress = 0;
        await this.uploadI();
        this.close();
      } catch {
        // do not quit
      } finally {
        this.processing = false;
        this.progressNote = String();
      }
    },

    async uploadI() {
      // Tags may be created which might throw
      let tags: number[] = [];
      if (this.tagsShown) {
        try {
          this.progressNote = this.t('memories', 'Creating tags');
          tags = (await this.refs().tags?.result?.())?.add ?? [];
        } catch (e: any) {
          showError(e);
          console.error(e);
          throw e;
        }
      }

      type UploadSource = { kind: 'file'; file: File } | { kind: 'nativex'; entry: IUploadNativeX };
      const queue: UploadSource[] = [
        ...this.files.map((file) => ({ kind: 'file' as const, file })),
        ...this.locals.map((entry) => ({ kind: 'nativex' as const, entry })),
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
      let maxProgress = this.files.reduce((sum, file) => sum + file.size, 0); // file size
      maxProgress += this.locals.length; // local size unknown, assume 1 each
      maxProgress += (tags.length ? 1 : 0) * queue.length * OP_FAC; // tags
      maxProgress += this.albums.length * queue.length * OP_FAC; // albums

      // Update progress bar
      let progress = 0;
      const addProgress = (delta: number) => {
        progress += delta;
        this.progress = maxProgress ? (progress * 100) / maxProgress : 0;
      };

      // Guard against closed modal
      const guardOpen = () => {
        if (!this.show) throw new Error('Modal closed');
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
        let path = this.uploadPath;
        if (!path.endsWith('/')) path += '/';

        if (source.kind === 'nativex') {
          // NativeX files upload natively without downloading
          try {
            const dest = path + source.entry.filename;
            this.progressNote = this.t('memories', 'Uploading {file}', { file: source.entry.filename });
            const fileid = await this.uploadNativeX(source.entry.auid, dest);
            guardOpen();
            uploaded.push({ fileid, filename: dest, name: source.entry.filename });
          } catch (e) {
            showError(this.t('memories', 'Failed to upload {file}', { file: source.entry.filename }));
            console.error(e);
            remaining.push(source);
          } finally {
            addProgress(1);
          }
        } else {
          // Browser files are uploaded using the uploader.
          const file = source.file;
          try {
            this.progressNote = this.t('memories', 'Uploading {file}', { file: file.name });

            const filename = path + file.name;
            const promise = (this.currentUpload = uploader.upload(filename, file));
            const res = await promise;
            this.currentUpload = null;

            const fileid = parseInt(res.response?.headers?.['oc-fileid'] ?? 0);
            if (!fileid) throw new Error('No fileid header in response');

            uploaded.push({ fileid, filename, name: file.name });
          } catch (e) {
            showError(this.t('memories', 'Failed to upload {file}', { file: file.name }));
            console.error(e);
            remaining.push(source);
          } finally {
            this.currentUpload = null;
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
      for (const album of this.albums) {
        guardOpen();

        this.progressNote = this.t('memories', 'Adding files to album {album}', { album: album.name });
        for await (const fileIds of dav.addToAlbum(album.user, album.name, photos)) {
          addProgress(OP_FAC);
        }
      }

      // Attach tags
      if (tags.length) {
        for (const photo of photos) {
          guardOpen();

          this.progressNote = this.t('memories', 'Attaching tags to {file}', { file: photo.basename! });
          try {
            await axios.patch<null>(API.TAG_SET(photo.fileid), { add: tags });
          } catch (e) {
            showError(this.t('memories', 'Failed to attach tags to {file}', { file: photo.basename! }));
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
        showError(this.t('memories', 'Some files have not been uploaded.'));
        this.files = remaining.filter((s): s is { kind: 'file'; file: File } => s.kind === 'file').map((s) => s.file);
        this.locals = remaining
          .filter((s): s is { kind: 'nativex'; entry: IUploadNativeX } => s.kind === 'nativex')
          .map((s) => s.entry);
        throw new Error('Some files have not been uploaded.');
      }
    },

    async uploadNativeX(auid: string, filename: string): Promise<number> {
      const res = await fetch(nativex.NAPI.UPLOAD_LOCAL(auid, filename));
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const fileid = parseInt((await res.json())?.fileid ?? 0);
      if (!fileid) throw new Error('No fileid in response');
      return fileid;
    },
  },
});
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
