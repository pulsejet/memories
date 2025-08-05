<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="normal" :can-close="pane === 0">
    <template #title>
      {{ n('memories', 'Upload {n} file', 'Upload {n} files', files.length, { n: files.length }) }}
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
          <NcCheckboxRadioSwitch :checked="albums.length > 0" :disabled="processing" @update:checked="pane = 1">
            {{ t('memories', 'Add to albums') }}
            <br />
            <span class="switch-subtitle">{{ albumNames }}</span>
          </NcCheckboxRadioSwitch>

          <NcCheckboxRadioSwitch :checked.sync="tagsShown" :disabled="processing">
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
          <NcButton @click="upload" type="primary" :disabled="processing">
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
import Vue, { defineComponent, defineAsyncComponent } from 'vue';

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
import { API } from '@services/API';

import type { IAlbum, IPhoto } from '@typings';
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
  }),

  created() {
    console.assert(!_m.modals.upload, 'UploadModal created twice');
    _m.modals.upload = this.open;

    // create right header button
    const header = document.querySelector<HTMLDivElement>('.header-right, .header-end');
    if (header && utils.uid) {
      // const div = document.createElement('div');
      // header.prepend(div);
      // const component = new Vue({ render: (h) => h(UploadMenuItem) });
      // component.$mount(div);
    }
  },

  computed: {
    refs() {
      return this.$refs as {
        tags?: InstanceType<typeof EditTags>;
      };
    },

    albumNames() {
      if (!this.albums.length) {
        return this.t('memories', 'No albums selected');
      }

      return this.albums.map((album) => album.name).join(', ');
    },
  },

  methods: {
    open() {
      // cannot upload to public shares
      if (this.routeIsPublic) return;

      // reset everything
      this.pane = 0;
      this.files = [];
      this.albums = [];
      this.tagsShown = false;
      this.processing = false;
      this.progress = 0;

      // choose first path of timeline path
      this.uploadPath = this.config.timeline_path.split(';')?.[0] ?? '/';

      // choose current folder if in folders view
      if (this.routeIsFolders) {
        this.uploadPath = utils.getFolderRoutePath(this.config.folders_path);
      }

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

    cleanup() {
      this.show = false;
      this.files = [];
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
          tags = (await this.refs.tags?.result?.())?.add ?? [];
        } catch (e) {
          showError(e);
          console.error(e);
          throw e;
        }
      }

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
      maxProgress += (tags.length ? 1 : 0) * this.files.length * OP_FAC; // tags
      maxProgress += this.albums.length * this.files.length * OP_FAC; // albums

      // Update progress bar
      let progress = 0;
      const addProgress = (delta: number) => {
        progress += delta;
        this.progress = (progress * 100) / maxProgress;
      };

      // Guard against closed modal
      const guardOpen = () => {
        if (!this.show) throw new Error('Modal closed');
      };

      // List of successful uploads
      const uploaded = [] as {
        fileid: number;
        filename: string;
        file: File;
      }[];

      // Start upload process
      const uploader = getUploader();
      for (const file of this.files) {
        guardOpen();

        // add slash to upload path
        let path = this.uploadPath;
        if (!path.endsWith('/')) path += '/';

        try {
          this.progressNote = this.t('memories', 'Uploading {file}', { file: file.name });

          const filename = path + file.name;
          const promise = (this.currentUpload = uploader.upload(filename, file));
          const res = await promise;
          this.currentUpload = null;

          const fileid = parseInt(res.response?.headers?.['oc-fileid'] ?? 0);
          if (!fileid) throw new Error('No fileid header in response');

          uploaded.push({ fileid, filename, file });
        } catch (e) {
          showError(this.t('memories', 'Failed to upload {file}', { file: file.name }));
          console.error(e);
        } finally {
          this.currentUpload = null;
          addProgress(file.size);
        }
      }

      // Make IPhoto types for album calls
      const photos = uploaded.map((f) => ({
        fileid: f.fileid,
        basename: f.file.name,
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
      if (uploaded.length !== this.files.length) {
        showError(this.t('memories', 'Some files have not been uploaded.'));
        this.files = this.files.filter((file) => !uploaded.some((up) => up.file === file));
        throw new Error('Some files have not been uploaded.');
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.inner {
  margin-top: 1em;

  :deep .checkbox-content {
    max-width: calc(100% - 20px);
    padding: 4px 10px;

    &__text {
      display: block;
      line-height: 1.1em;
    }
  }
}

.switch-subtitle {
  font-size: 0.82em;
}

.options {
  margin-top: 10px;

  .tags-pane {
    :deep .outer {
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
