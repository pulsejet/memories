<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Edit metadata') }}
    </template>

    <template #buttons>
      <NcButton @click="save" class="button" variant="error" v-if="photos" :disabled="processing">
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

<script setup lang="ts">
import { ref, useTemplateRef, defineAsyncComponent } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import Modal from './Modal.vue';

import EditDate from './EditDate.vue';
import EditTags from './EditTags.vue';
import EditExif from './EditExif.vue';
import EditLocation from './EditLocation.vue';
import EditOrientation from './EditOrientation.vue';

import { showWarning, showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { useModal } from '@services/modal';
import { t, n } from '@services/l10n';
import { config } from '@services/user-config';
import { constants as c } from '@services/utils';
import { API } from '@services/API';
import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IExif, IImageInfo, IPhoto } from '@typings';

const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const editDate = useTemplateRef<InstanceType<typeof EditDate>>('editDate');
const editTags = useTemplateRef<InstanceType<typeof EditTags>>('editTags');
const editExif = useTemplateRef<InstanceType<typeof EditExif>>('editExif');
const editLocation = useTemplateRef<InstanceType<typeof EditLocation>>('editLocation');
const editOrientation = useTemplateRef<InstanceType<typeof EditOrientation>>('editOrientation');

const photos = ref<IPhoto[] | null>(null);
const sections = ref<number[]>([]);
const processing = ref(false);
const progress = ref(0);
const state = ref(0);

console.assert(!_m.modals.editMetadata, 'EditMetadataModal created twice');
_m.modals.editMetadata = open;

async function open(photosIn: IPhoto[], sectionsIn: number[] = [1, 2, 3, 4]) {
  const current = (state.value = Math.random());
  show.value = true;
  processing.value = true;
  sections.value = sectionsIn;
  progress.value = 0;

  // Include identical copies hidden by de-duplication (#1299)
  let filtered = photosIn.flatMap((p) => [p, ...(p.dups ?? [])]);

  // Filter out forbidden MIME types
  filtered = filtered.filter((p) => {
    if (c.FORBIDDEN_EDIT_MIMES.includes(p.mimetype ?? String())) {
      showError(t('memories', 'Cannot edit {name} of type {type}', { name: p.basename!, type: p.mimetype! }));
      return false;
    }

    // Extra filters if orientation is in the sections
    if (sectionsIn.includes(5)) {
      // Videos might work but we don't want to risk it
      if (p.mimetype?.startsWith('video/')) {
        showError(t('memories', 'Cannot edit rotation on videos ({name})', { name: p.basename! }));
        return false;
      }

      // Live photos cannot be edited because the orientation of the video
      // will remain the same and look wrong.
      if (p.liveid) {
        showError(t('memories', 'Cannot edit rotation on Live Photos ({name})', { name: p.basename! }));
        return false;
      }
    }

    return true;
  });

  // Load metadata for all photos
  await dav.fillImageInfo(filtered, { tags: 1 }, (count) => {
    progress.value = Math.round((count * 100) / filtered.length);
  });

  // Check if already quit
  if (!show.value || state.value !== current) return;

  // Use valid photos
  const valid = filterValid(filtered);
  if (valid.length === 0) {
    close();
    return;
  }

  // Warn user if any raw stacks are present
  if (valid.some((p) => p.stackraw?.length)) {
    showWarning(t('memories', 'Some selected items have stacked RAW files.\nRAW files will not be edited.'));
  }

  photos.value = valid;
  processing.value = false;
}

function cleanup() {
  show.value = false;
  photos.value = null;
  processing.value = false;
}

async function save() {
  // Perform validation
  try {
    editDate.value?.validate?.();
  } catch (e: any) {
    console.error(e);
    showError(e);
    return;
  }

  // Start processing
  let done = 0;
  progress.value = 0;
  processing.value = true;

  // Get exif fields diff
  const exifResult = {
    ...(editExif.value?.result?.() ?? {}),
    ...(editLocation.value?.result?.() ?? {}),
  };

  // Tags may be created which might throw
  let tagsResult: { add: number[]; remove: number[] } | null = null;
  try {
    tagsResult = (await editTags.value?.result?.()) ?? null;
  } catch (e: any) {
    processing.value = false;
    console.error(e);
    showError(e);
    return;
  }

  // EXIF update values
  const exifs = new Map<number, IExif>();
  for (const p of photos.value!) {
    // Basic EXIF fields
    const raw: IExif = structuredClone(exifResult as IExif);

    // Date header
    const date = editDate.value?.result?.(p);
    if (date) {
      raw.AllDates = date;
    }

    // Orientation
    const orientation = editOrientation.value?.result?.(p);
    if (orientation != null) {
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
    photos.value!.some(hasNoDate) &&
    (await utils.confirmDestructive({
      title: t('memories', 'Missing date metadata'),
      message: t(
        'memories',
        'Some items may be missing the date metadata. Do you want to attempt copying the currently known timestamp to the metadata (recommended)? Othewise, the timestamp may be reset to the current time.',
      ),
    }))
  ) {
    for (const p of photos.value!) {
      // Check if already has the date taken, or it if can't do anything
      if (!hasNoDate(p) || !p.datetaken) continue;

      // Get the date in EXIF format
      const dateTaken = utils.getExifDateStr(new Date(p.datetaken * 1000));
      const raw = exifs.get(p.fileid);
      raw!.AllDates = dateTaken;
    }
  }

  // Update exif fields
  const calls = photos.value!.map((p) => async () => {
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
    } catch (e: any) {
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
      progress.value = Math.round((done * 100) / (photos.value?.length ?? 100));
    }
  });

  for await (const _ of dav.runInParallel(calls, 8)) {
    // nothing to do
  }

  editOrientation.value?.reset();
  processing.value = false;
  close();

  // Trigger a soft refresh
  utils.bus.emit('memories:timeline:soft-refresh', null);
}

function filterValid(photosIn: IPhoto[]) {
  // Check if we have image info
  const valid = photosIn.filter((p) => p.imageInfo);
  if (valid.length !== photosIn.length) {
    const nPhotos = photosIn.length - valid.length;
    showError(
      n('memories', 'Failed to load metadata for {n} photo.', 'Failed to load metadata for {n} photos.', nPhotos, {
        n: nPhotos,
      }),
    );
  }

  // Check if photos are updatable
  const updatable = valid.filter((p) => p.imageInfo?.permissions?.includes('U'));
  if (updatable.length !== valid.length) {
    const nPhotos = valid.length - updatable.length;
    showError(
      n(
        'memories',
        '{n} photo cannot be edited (permissions error).',
        '{n} photos cannot be edited (permissions error).',
        nPhotos,
        { n: nPhotos },
      ),
    );
  }

  return updatable;
}
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
