<template>
  <div class="outer" v-if="fileid">
    <div v-if="title || description" class="exif-head" @click="editEXIF()">
      <div class="title" v-if="title">{{ title }}</div>
      <div class="description" v-if="description">{{ description }}</div>
    </div>

    <div v-if="isShared" class="shared-by">
      <div class="section-title">{{ t('memories', 'Shared By') }}</div>
      <div class="top-field">
        <NcAvatar key="uid" :user="baseInfo.owneruid" :showUserStatus="false" :size="24" />
        <span class="name">{{ baseInfo.ownername }}</span>
      </div>
    </div>

    <div v-if="people.length" class="people">
      <div class="section-title">{{ t('memories', 'People') }}</div>
      <div class="container" v-for="face of people" :key="face.cluster_id">
        <Cluster class="cluster--rounded" :data="face" :counters="false"> </Cluster>
      </div>
    </div>

    <div v-if="albums.length">
      <div class="section-title">{{ t('memories', 'Albums') }}</div>
      <AlbumsList class="albums" :albums="albums" />
    </div>

    <div class="section-title">{{ t('memories', 'Metadata') }}</div>
    <div v-for="field of topFields" :key="field.title" :class="`top-field top-field--${field.id}`">
      <div class="icon">
        <component :is="field.icon" :size="24" />
      </div>

      <div class="text">
        <template v-if="field.href">
          <a :href="field.href" target="_blank" rel="noopener noreferrer">
            <span class="title">{{ field.title }}</span>
          </a>
        </template>
        <template v-else>
          <span class="title">{{ field.title }}</span>
        </template>

        <template v-if="field.subtitle.length">
          <br />
          <span class="subtitle">
            <span class="part" v-for="part in field.subtitle" :key="part">
              {{ part }}
            </span>
          </span>
        </template>
      </div>

      <div class="edit" v-if="canEdit && field.edit">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Edit')" @click="field.edit?.()">
            {{ t('memories', 'Edit') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div v-if="lat && lon" class="map">
      <MapStandalone :center="[lat, lon]" :pins="[[lat, lon]]" />
    </div>
  </div>
  <div class="loading-icon fill-block" v-else-if="loading">
    <XLoadingIcon />
  </div>
  <div v-else-if="error">
    {{ t('memories', 'Failed to load metadata') }}
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent, markRaw } from 'vue';
import type { Component } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
const NcAvatar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAvatar'));

import axios from '@nextcloud/axios';
import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';

import { config } from '@services/user-config';
import { routeIs } from '@services/router';

import Cluster from '@components/frame/Cluster.vue';
import AlbumsList from '@components/modal/AlbumsList.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';
import MapStandalone from '@components/MapStandalone.vue';

import EditIcon from 'vue-material-design-icons/Pencil.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import CameraIrisIcon from 'vue-material-design-icons/CameraIris.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';

import * as utils from '@services/utils';
import { cacheData, getCachedData } from '@services/cache';
import * as dav from '@services/dav';
import { t } from '@services/l10n';

import type { IImageInfo, IPhoto, IExif } from '@typings';
import type { IFolder, INode, IView } from '@nextcloud/files';

interface TopField {
  id: string;
  title: string;
  subtitle: string[];
  icon: Component;
  href?: string;
  edit?: () => void;
}

const props = defineProps<{
  /** File node when mounted as Files sidebar tab (custom element) */
  node?: INode;
  // eslint-disable-next-line vue/no-unused-properties -- Required on the web component interface
  active?: boolean;
  // eslint-disable-next-line vue/no-unused-properties -- Required on the web component interface
  folder?: IFolder;
  // eslint-disable-next-line vue/no-unused-properties -- Required on the web component interface
  view?: IView;
}>();
const fileid = ref<number | null>(null);
const filename = ref('');
const exif = ref({} as IExif);
const baseInfo = ref({} as IImageInfo);
const error = ref(false);

const loading = ref(0);
const state = ref(0);

/** Whether the current user may edit this file */
const canEdit = computed(() => baseInfo.value?.permissions?.includes('U'));

/** Title EXIF value */
const title = computed(() => exif.value.Title || null);

/** Description EXIF value */
const description = computed(() => exif.value.Description || null);

/** Date taken info */
const dateOriginal = computed(() => {
  // Try to get timezone info
  let dateWithTz: DateTime | null = null;
  const valid = () => dateWithTz?.isValid;

  // If we have an actual epoch, we can shift the date to the correct timezone
  if (!valid() && exif.value.DateTimeEpoch) {
    const date = DateTime.fromSeconds(exif.value.DateTimeEpoch);
    if (date.isValid) {
      const tzOffset = exif.value.OffsetTimeOriginal || exif.value.OffsetTime; // e.g. -05:00
      const tzId = exif.value.LocationTZID; // e.g. America/New_York

      // Use timezone offset if available
      if (!valid() && tzOffset) {
        dateWithTz = date.setZone(`UTC${tzOffset}`);
      }

      // Fall back to tzId
      if (!valid() && tzId) {
        dateWithTz = date.setZone(tzId);
      }
    }
  }

  // If tz info is unavailable / wrong, we will show the local time only
  // In this case, use the datetaken instead, which is guaranteed to be local, shifted to UTC
  if (!valid() && baseInfo.value.datetaken) {
    const date = DateTime.fromSeconds(baseInfo.value.datetaken);
    if (date.isValid) {
      dateWithTz = date.setZone('UTC');
    }
  }

  // Return only if we found a valid date
  return valid() ? dateWithTz : null;
});

/** Localized long date string */
const dateOriginalStr = computed(() => utils.getLongDateStr(new Date(baseInfo.value.datetaken * 1000), true));

/** Localized time string, with zone when known */
const dateOriginalTime = computed(() => {
  if (!dateOriginal.value) return null;

  const fields: (keyof IExif)[] = ['OffsetTimeOriginal', 'OffsetTime', 'LocationTZID'];
  const hasTz = fields.some((key) => exif.value[key]);

  const format = 't' + (hasTz ? ' ZZ' : '');

  return [dateOriginal.value.toFormat(format, { locale: getCanonicalLocale() })];
});

/** Camera make and model info */
const camera = computed(() => {
  const make = exif.value.Make;
  const model = exif.value.Model;
  if (!make || !model) return null;
  if (model.startsWith(make)) return model;
  return `${make} ${model}`;
});

/** Aperture, shutter, focal length and ISO parts */
const cameraSub = computed(() => {
  const f = exif.value.FNumber || exif.value.Aperture;
  const s = shutterSpeed.value;
  const len = exif.value.FocalLength;
  const iso = exif.value.ISO;

  const parts: string[] = [];
  if (f) parts.push(`f/${f}`);
  if (s) parts.push(`${s}`);
  if (len) parts.push(`${len}mm`);
  if (iso) parts.push(`ISO${iso}`);
  return parts;
});

/** Convert shutter speed decimal to 1/x format */
const shutterSpeed = computed(() => {
  const speed = Number(exif.value.ShutterSpeedValue || exif.value.ShutterSpeed || exif.value.ExposureTime);
  if (!speed) return null;

  if (speed < 1) {
    return `1/${Math.round(1 / speed)}`;
  } else {
    return `${Math.round(speed * 10) / 10}s`;
  }
});

/** Image info */
const imageInfoTitle = computed(() => {
  if (config.sidebar_filepath && filepath.value) {
    return filepath.value.replace(/^\//, ''); // remove leading slash
  }

  return baseInfo.value.basename;
});

/** Path to file excluding user directory */
const filepath = computed(() => baseInfo.value?.filename ?? null);

/** Dimensions and megapixel parts */
const imageInfoSub = computed(() => {
  const parts: string[] = [];
  let mp = Number(exif.value.Megapixels);

  if (baseInfo.value.w && baseInfo.value.h) {
    parts.push(`${baseInfo.value.w}x${baseInfo.value.h}`);

    if (!mp) {
      mp = (baseInfo.value.w * baseInfo.value.h) / 1000000;
    }
  }

  if (mp) {
    parts.unshift(`${mp.toFixed(1)}MP`);
  }

  return parts;
});

/** GPS latitude as number */
const lat = computed(() => Number(exif.value.GPSLatitude));

/** GPS longitude as number */
const lon = computed(() => Number(exif.value.GPSLongitude));

/** Human address, falling back to coordinates */
const address = computed(() => {
  if (baseInfo.value.address) {
    return baseInfo.value.address;
  }

  if (lat.value && lon.value) {
    return `${lat.value.toFixed(6)}, ${lon.value.toFixed(6)}`;
  }

  return undefined;
});

/** Localized tag names */
const tagNames = computed(() => Object.values(baseInfo.value?.tags || {}).map((tag: string) => t('recognize', tag)));

/** Comma-joined tag names */
const tagNamesStr = computed(() => (tagNames.value.length > 0 ? tagNames.value.join(', ') : null));

/** OpenStreetMap link for the coordinates */
const mapFullUrl = computed(
  () => `https://www.openstreetmap.org/?mlat=${lat.value}&mlon=${lon.value}#map=18/${lat.value}/${lon.value}`,
);

/** Albums containing this file, minus hidden ones unless configured */
const albums = computed(() => {
  let list = baseInfo.value?.clusters?.albums ?? [];

  // Filter out hidden albums
  if (!config.show_hidden_albums) {
    list = list.filter((a) => !a.name.startsWith('.'));
  }

  return list;
});

/** Faces for this file, backend depends on route and config */
const people = computed(() => {
  const clusters = baseInfo.value?.clusters;

  // force face-recognition on its own route, or if recognize is disabled
  if (routeIs.FaceRecognition || !config.recognize_enabled) {
    return clusters?.facerecognition ?? [];
  }

  return clusters?.recognize ?? [];
});

/** Whether this file is shared by someone else */
const isShared = computed(() => !!baseInfo.value.owneruid && baseInfo.value.owneruid !== utils.uid);

/** Rows shown in the metadata section */
const topFields = computed(() => {
  const list: TopField[] = [];

  if (dateOriginal.value) {
    list.push({
      id: 'date',
      title: dateOriginalStr.value!,
      subtitle: dateOriginalTime.value!,
      icon: markRaw(CalendarIcon),
      edit: editDate,
    });
  }

  if (camera.value) {
    list.push({
      id: 'camera',
      title: camera.value,
      subtitle: cameraSub.value,
      icon: markRaw(CameraIrisIcon),
    });
  }

  if (imageInfoTitle.value) {
    list.push({
      id: 'image-info', // adds class
      title: imageInfoTitle.value,
      subtitle: imageInfoSub.value,
      icon: markRaw(ImageIcon),
      href: filepath.value
        ? dav.viewInFolderUrl({
            fileid: fileid.value!,
            filename: filepath.value,
          })
        : undefined,
    });
  }

  if (tagNamesStr.value) {
    list.push({
      id: 'tags',
      title: tagNamesStr.value,
      subtitle: [],
      icon: markRaw(TagIcon),
      edit: editTags,
    });
  }

  if (address.value || canEdit.value) {
    list.push({
      id: 'location',
      title: address.value || t('memories', 'No coordinates'),
      subtitle: address.value ? [] : [t('memories', 'Click edit to set location')],
      icon: markRaw(LocationIcon),
      href: address.value ? mapFullUrl.value : undefined,
      edit: editGeo,
    });
  }

  return list;
});

/** Await a promise, dropping the result if superseded by a newer load */
async function guardState<T>(promise: Promise<T>): Promise<T | null> {
  const snapshot = state.value;
  try {
    loading.value++;
    const res = await promise;
    if (snapshot === state.value) return res;
    return null;
  } catch (err) {
    error.value = true;
    throw err;
  } finally {
    if (snapshot === state.value) loading.value--;
  }
}

/** Reset state unless the given file is already current */
function invalidateUnless(id: number) {
  if (fileid.value === id) return;
  state.value = Math.random();
  loading.value = 0;
  error.value = false;
  fileid.value = null;
  exif.value = {};
}

/** Load metadata for a photo, cache-first then server */
async function update(photo: number | IPhoto): Promise<IImageInfo | null> {
  invalidateUnless(0);

  // Use a consistent URL for metadata.
  const url = utils.getImageInfoUrl(photo, config);

  // Helper to apply additional fields.
  const applyImageInfo = (data: IImageInfo) => {
    baseInfo.value = data;
    fileid.value = data.fileid;
    filename.value = data.basename;
    exif.value = data.exif ?? {};
  };

  // Attempt to get it from the cache first.
  let wasCached = false;
  try {
    const snapshot = state.value;
    const cached = await getCachedData<IImageInfo>(url);
    if (cached && snapshot === state.value) {
      applyImageInfo(cached);
      wasCached = true;
    }
  } catch {}

  // Always refresh the metadata from server.
  try {
    const res = await guardState(axios.get<IImageInfo>(url));
    if (!res) return null;
    applyImageInfo(res.data);
    cacheData(url, res.data);
  } catch (err) {
    if (wasCached) {
      error.value = false;
    } else {
      throw err;
    }
  }

  return baseInfo.value;
}

/** Reload metadata for the current file */
async function refresh() {
  if (fileid.value) await update(fileid.value);
}

/** Open the edit dialog on the date section */
function editDate() {
  _m.modals.editMetadata([_m.viewer.currentPhoto!], [1]);
}

/** Open the edit dialog on the tags section */
function editTags() {
  _m.modals.editMetadata([_m.viewer.currentPhoto!], [2]);
}

/** Open the edit dialog on the EXIF section */
function editEXIF() {
  _m.modals.editMetadata([_m.viewer.currentPhoto!], [3]);
}

/** Open the edit dialog on the location section */
function editGeo() {
  _m.modals.editMetadata([_m.viewer.currentPhoto!], [4]);
}

/** Refresh when the current file changes on disk */
function handleFileUpdated({ fileid: updated }: utils.BusEvent['files:file:updated']) {
  if (updated && fileid.value === updated) {
    refresh();
  }
}

onMounted(() => {
  utils.bus.on('files:file:updated', handleFileUpdated);
  utils.bus.on('memories:albums:update', refresh);
});

onBeforeUnmount(() => {
  utils.bus.off('files:file:updated', handleFileUpdated);
  utils.bus.off('memories:albums:update', refresh);
});

watch(
  () => props.node,
  () => {
    const id = Number(props.node?.fileid ?? props.node?.id ?? 0);
    if (id) {
      update(id);
    }
  },
  { immediate: true },
);

defineExpose({ fileid, update, invalidateUnless });
</script>

<style lang="scss" scoped>
.section-title {
  font-variant: all-small-caps;
  padding: 0px 6px;
}

a {
  color: inherit; // forced-dark sheet
}

.exif-head {
  padding: 4px 6px;

  .title {
    font-weight: 500;
  }

  .description,
  .title {
    font-size: 0.93em;
    line-height: 1.5em;
    padding-bottom: 3px;

    cursor: pointer;
    &:hover {
      text-decoration: underline;
      text-decoration-color: #ddd;
      text-underline-offset: 4px;
    }
  }
}

.shared-by {
  > .top-field {
    margin-top: 6px;
    margin-bottom: 10px;
  }
  .name {
    margin-left: 8px;
  }
}

.people {
  margin-bottom: 6px;
  > .section-title {
    margin-bottom: 4px;
  }
  > .container {
    width: calc(100% / 3);
    aspect-ratio: 1;
    position: relative;
    display: inline-block;
    vertical-align: top;
    font-size: 0.85em;

    @media (max-width: 768px) {
      font-size: 0.95em;
    }
  }
}

.albums {
  font-size: 0.96em;
  :deep(.line-one__title) {
    font-weight: 400 !important; // no bold title
  }
}

.top-field {
  margin-left: 10px;
  margin-top: 10px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;

  .icon,
  .edit {
    display: inline-block;
    margin-right: 10px;

    :deep(.material-design-icon) {
      color: var(--color-text-maxcontrast);
    }
  }
  .edit {
    transform: translateX(10px);
  }
  .text {
    display: inline-block;
    word-break: break-word;
    flex: 1;

    .subtitle {
      font-size: 0.95em;
      .part {
        margin-right: 5px;
      }
    }
  }

  &--image-info .title {
    user-select: all; // filename or basename
  }
}

.loading-icon {
  height: 75%;
}

.map {
  width: 100%;
  aspect-ratio: 16 / 10;
  min-height: 200px;
  max-height: 250px;
  border-radius: 16px;
  overflow: hidden;
}
</style>
