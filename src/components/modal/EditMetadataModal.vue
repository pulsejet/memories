<template>
  <Modal v-if="show" @close="close">
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
        <EditDate ref="editDate" :photos="photos" />
      </div>

      <div v-if="config.systemtags_enabled && sections.includes(2)">
        <div class="title-text">
          {{ t('memories', 'Collaborative Tags') }}
        </div>
        <EditTags ref="editTags" :photos="photos" />
        <div class="tag-padding" v-if="sections.length === 1"></div>
      </div>

      <div v-if="sections.includes(3)">
        <div class="title-text">
          {{ t('memories', 'EXIF Fields') }}
        </div>
        <EditExif ref="editExif" :photos="photos" @save="save" />
      </div>

      <div v-if="sections.includes(4)">
        <div class="title-text">
          {{ t('memories', 'Geolocation') }}
        </div>
        <EditLocation ref="editLocation" :photos="photos" />
      </div>
    </div>

    <div v-if="processing" class="progressbar">
      <NcProgressBar :value="progress" :error="true" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { IPhoto } from '../../types';

import UserConfig from '../../mixins/UserConfig';
import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar');
import Modal from './Modal.vue';

import EditDate from './EditDate.vue';
import EditTags from './EditTags.vue';
import EditExif from './EditExif.vue';
import EditLocation from './EditLocation.vue';

import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import * as dav from '../../services/dav';
import * as utils from '../../services/utils';
import { API } from '../../services/API';

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
  },

  mixins: [UserConfig],

  data: () => ({
    photos: null as IPhoto[] | null,
    sections: [] as number[],
    show: false,
    processing: false,
    progress: 0,
    state: 0,
  }),

  mounted() {
    globalThis.editMetadata = this.open;
  },

  methods: {
    async open(photos: IPhoto[], sections: number[] = [1, 2, 3, 4]) {
      const state = (this.state = Math.random());
      this.show = true;
      this.processing = true;
      this.sections = sections;

      let done = 0;
      this.progress = 0;

      // Load metadata for all photos
      const calls = photos.map((p) => async () => {
        try {
          const url = API.Q(API.IMAGE_INFO(p.fileid), { tags: 1 });
          const res = await axios.get<any>(url);
          p.datetaken = res.data.datetaken;
          p.imageInfo = res.data;
        } catch (error) {
          console.error('Failed to get date info for', p.fileid, error);
        } finally {
          done++;
          this.progress = Math.round((done * 100) / photos.length);
        }
      });

      for await (const _ of dav.runInParallel(calls, 8)) {
        // nothing to do
      }

      // Check if already quit
      if (!this.show || this.state !== state) return;

      // Use valid photos
      const valid = this.filterValid(photos);
      if (valid.length === 0) {
        this.close();
        return;
      }

      this.photos = valid;
      this.processing = false;
    },

    close() {
      this.photos = null;
      this.show = false;
      this.processing = false;
    },

    async save() {
      // Perform validation
      try {
        (<any>this.$refs.editDate)?.validate?.();
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
        ...((<any>this.$refs.editExif)?.result?.() || {}),
        ...((<any>this.$refs.editLocation)?.result?.() || {}),
      };

      // Tags may be created which might throw
      let tagsResult: number[] | null = null;
      try {
        tagsResult = (await (<any>this.$refs.editTags)?.result?.()) || null;
      } catch (e) {
        this.processing = false;
        console.error(e);
        showError(e);
        return;
      }

      // Update exif fields
      const calls = this.photos!.map((p) => async () => {
        try {
          let dirty = false;
          const fileid = p.fileid;

          // Basic EXIF fields
          const raw = JSON.parse(JSON.stringify(exifResult));

          // Date
          const date = (<any>this.$refs.editDate)?.result?.(p);
          if (date) {
            raw.DateTimeOriginal = date;
            raw.CreateDate = date;
          }

          // Update EXIF if required
          if (Object.keys(raw).length > 0) {
            await axios.patch<any>(API.IMAGE_SETEXIF(fileid), { raw });
            dirty = true;
          }

          // Update tags if required
          if (tagsResult) {
            await axios.patch<any>(API.TAG_SET(fileid), tagsResult);
            dirty = true;
          }

          // Refresh UX
          if (dirty) {
            p.imageInfo = null;
            utils.bus.emit('files:file:updated', { fileid });
          }
        } catch (e) {
          console.error('Failed to save metadata for', p.fileid, e);
          if (e.response?.data?.message) {
            showError(e.response.data.message);
          } else {
            showError(e);
          }
        } finally {
          done++;
          this.progress = Math.round((done * 100) / this.photos!.length);
        }
      });

      for await (const _ of dav.runInParallel(calls, 8)) {
        // nothing to do
      }

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
          })
        );
      }

      // Check if photos are updatable
      const updatable = valid.filter((p) => p.imageInfo?.permissions?.includes('U'));
      if (updatable.length !== valid.length) {
        showError(
          this.t('memories', '{n} photos cannot be edited (permissions error).', {
            n: valid.length - updatable.length,
          })
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
