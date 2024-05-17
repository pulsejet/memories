<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Edit metadata') }}
    </template>

    <template #buttons>
      <NcButton @click="save" class="button" type="error" v-if="photos" :disabled="processing">
        {{ t('memories', 'Save') }}
      </NcButton>
    </template>

    <div v-if="photos">
      <div v-if="sections.includes(1)">
        <div class="title-text">
          {{ t('memories', 'Date / Time') }}
        </div>
        <EditDate ref="editDate" :photos="photos" :disabled="processing" @save="save" />
      </div>

      <div v-if="config.systemtags_enabled && sections.includes(2)">
        <div class="title-text">
          {{ t('memories', 'Collaborative Tags') }}
        </div>
        <EditTags ref="editTags" :photos="photos" :disabled="processing" />
        <div class="tag-padding" v-if="sections.length === 1"></div>
      </div>

      <div v-if="sections.includes(3)">
        <div class="title-text">
          {{ t('memories', 'EXIF Fields') }}
        </div>
        <EditExif ref="editExif" :photos="photos" :disabled="processing" @save="save" />
      </div>

      <div v-if="sections.includes(4)">
        <div class="title-text">
          {{ t('memories', 'Geolocation') }}
        </div>
        <EditLocation ref="editLocation" :photos="photos" :disabled="processing" />
      </div>

      <div v-if="sections.includes(5)">
        <div class="title-text">
          {{ t('memories', 'Orientation (EXIF)') }}
        </div>
        <EditOrientation ref="editOrientation" :photos="photos" :disabled="processing" />
      </div>
    </div>

    <div v-if="processing" class="progressbar">
      <NcProgressBar :value="progress" :error="true" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar.js');

import UserConfig from '@mixins/UserConfig';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import EditDate from './EditDate.vue';
import EditTags from './EditTags.vue';
import EditExif from './EditExif.vue';
import EditLocation from './EditLocation.vue';
import EditOrientation from './EditOrientation.vue';

import { showWarning, showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import { API } from '@services/API';

import type { IExif, IImageInfo, IPhoto } from '@typings';

export default defineComponent({
  components: {
    NcButton,
    NcTextField,
    NcProgressBar,
    Modal,

    EditDate,
    EditTags,
    EditExif,
    EditLocation,
    EditOrientation,
  },

  mixins: [UserConfig, ModalMixin],

  data: () => ({
    photos: null as IPhoto[] | null,
    sections: [] as number[],
    processing: false,
    progress: 0,
    state: 0,
  }),

  computed: {
    refs() {
      return this.$refs as {
        editDate?: InstanceType<typeof EditDate>;
        editTags?: InstanceType<typeof EditTags>;
        editExif?: InstanceType<typeof EditExif>;
        editLocation?: InstanceType<typeof EditLocation>;
        editOrientation?: InstanceType<typeof EditOrientation>;
      };
    },
  },

  created() {
    console.assert(!_m.modals.editMetadata, 'EditMetadataModal created twice');
    _m.modals.editMetadata = this.open;
  },

  methods: {
    async open(photos: IPhoto[], sections: number[] = [1, 2, 3, 4]) {
      const state = (this.state = Math.random());
      this.show = true;
      this.processing = true;
      this.sections = sections;
      this.progress = 0;

      // Filter out forbidden MIME types
      photos = photos.filter((p) => {
        if (this.c.FORBIDDEN_EDIT_MIMES.includes(p.mimetype ?? String())) {
          showError(this.t('memories', 'Cannot edit {name} of type {type}', { name: p.basename!, type: p.mimetype! }));
          return false;
        }

        // Extra filters if orientation is in the sections
        if (sections.includes(5)) {
          // Videos might work but we don't want to risk it
          if (p.mimetype?.startsWith('video/')) {
            showError(this.t('memories', 'Cannot edit rotation on videos ({name})', { name: p.basename! }));
            return false;
          }

          // Live photos cannot be edited because the orientation of the video
          // will remain the same and look wrong.
          if (p.liveid) {
            showError(this.t('memories', 'Cannot edit rotation on Live Photos ({name})', { name: p.basename! }));
            return false;
          }
        }

        return true;
      });

      // Load metadata for all photos
      await dav.fillImageInfo(photos, { tags: 1 }, (count) => {
        this.progress = Math.round((count * 100) / photos.length);
      });

      // Check if already quit
      if (!this.show || this.state !== state) return;

      // Use valid photos
      const valid = this.filterValid(photos);
      if (valid.length === 0) {
        this.close();
        return;
      }

      // Warn user if any raw stacks are present
      if (valid.some((p) => p.stackraw?.length)) {
        showWarning(this.t('memories', 'Some selected items have stacked RAW files.\nRAW files will not be edited.'));
      }

      this.photos = valid;
      this.processing = false;
    },

    cleanup() {
      this.show = false;
      this.photos = null;
      this.processing = false;
    },

    async save() {
      // Perform validation
      try {
        this.refs.editDate?.validate?.();
      } catch (e) {
        console.error(e);
        showError(e);
        return;
      }

      // Start processing
      let done = 0;
      this.progress = 0;
      this.processing = true;

      // Get exif fields diff
      const exifResult = {
        ...(this.refs.editExif?.result?.() || {}),
        ...(this.refs.editLocation?.result?.() || {}),
      };

      // Tags may be created which might throw
      let tagsResult: { add: number[]; remove: number[] } | null = null;
      try {
        tagsResult = (await this.refs.editTags?.result?.()) ?? null;
      } catch (e) {
        this.processing = false;
        console.error(e);
        showError(e);
        return;
      }

      // EXIF update values
      const exifs = new Map<number, IExif>();
      for (const p of this.photos!) {
        // Basic EXIF fields
        const raw: IExif = JSON.parse(JSON.stringify(exifResult));

        // Date header
        const date = this.refs.editDate?.result?.(p);
        if (date) {
          raw.AllDates = date;
        }

        // Orientation
        const orientation = this.refs.editOrientation?.result?.(p);
        if (orientation !== null && orientation !== undefined) {
          raw.Orientation = orientation;
        }

        exifs.set(p.fileid, raw);
      }

      // If a photo has no EXIF date header then updating the metadata will erase
      // the date taken. We need to prompt the user to keep the date taken.
      const hasNoDate = (p: IPhoto) => {
        const exif = p.imageInfo?.exif;
        const hasExifDate = Boolean(exif?.DateTimeOriginal || exif?.CreateDate);
        const isSettingDate = Boolean(exifs.get(p.fileid)!.AllDates);
        return !hasExifDate && !isSettingDate;
      };

      if (
        this.photos!.some(hasNoDate) &&
        (await utils.confirmDestructive({
          title: this.t('memories', 'Missing date metadata'),
          message: this.t(
            'memories',
            'Some items may be missing the date metadata. Do you want to attempt copying the currently known timestamp to the metadata (recommended)? Othewise, the timestamp may be reset to the current time.',
          ),
        }))
      ) {
        for (const p of this.photos!) {
          // Check if already has the date taken, or it if can't do anything
          if (!hasNoDate(p) || !p.datetaken) continue;

          // Get the date in EXIF format
          const dateTaken = utils.getExifDateStr(new Date(p.datetaken * 1000));
          const raw = exifs.get(p.fileid);
          raw!.AllDates = dateTaken;
        }
      }

      // Update exif fields
      const calls = this.photos!.map((p) => async () => {
        let dirty = false;
        const fileid = p.fileid;

        try {
          // Update EXIF if required
          const raw = exifs.get(fileid) ?? {};
          if (Object.keys(raw).length > 0) {
            const info = await axios.patch<IImageInfo>(API.IMAGE_SETEXIF(fileid), { raw });
            dirty = true;

            // Update image size
            p.h = info.data?.h ?? p.h;
            p.w = info.data?.w ?? p.w;

            // If orientation was updated we need to change
            // the ETag so that the preview is updated.
            // Deliberately don't change the tag otherwise,
            // so there's no need to re-download the image.
            if (raw.Orientation) {
              p.etag = info.data?.etag ?? p.etag;
            }
          }

          // Update tags if required
          if (tagsResult) {
            await axios.patch<null>(API.TAG_SET(fileid), tagsResult);
            dirty = true;
          }
        } catch (e) {
          console.error('Failed to save metadata for', p.fileid, e);
          if (e.response?.data?.message) {
            showError(e.response.data.message);
          } else {
            showError(e);
          }
        } finally {
          // Refresh UX
          if (dirty) {
            p.imageInfo = null;
            utils.bus.emit('files:file:updated', { fileid });
          }

          // Update progress
          done++;
          this.progress = Math.round((done * 100) / (this.photos?.length ?? 100));
        }
      });

      for await (const _ of dav.runInParallel(calls, 8)) {
        // nothing to do
      }

      this.refs.editOrientation?.reset();
      this.processing = false;
      this.close();

      // Trigger a soft refresh
      utils.bus.emit('memories:timeline:soft-refresh', null);
    },

    filterValid(photos: IPhoto[]) {
      // Check if we have image info
      const valid = photos.filter((p) => p.imageInfo);
      if (valid.length !== photos.length) {
        showError(
          this.t('memories', 'Failed to load metadata for {n} photos.', {
            n: photos.length - valid.length,
          }),
        );
      }

      // Check if photos are updatable
      const updatable = valid.filter((p) => p.imageInfo?.permissions?.includes('U'));
      if (updatable.length !== valid.length) {
        showError(
          this.t('memories', '{n} photos cannot be edited (permissions error).', {
            n: valid.length - updatable.length,
          }),
        );
      }

      return updatable;
    },
  },
});
</script>

<style scoped lang="scss">
.title-text {
  font-size: 1.05em;
  font-weight: 500;
  margin-top: 25px;

  &:first-of-type {
    margin-top: 10px;
  }
}

.tag-padding {
  height: 200px;
  width: 100%;
  display: block;
}

.progressbar {
  margin-top: 10px;
}
</style>
