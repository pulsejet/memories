<template>
  <div class="loading-icon fill-block" v-if="loading">
    <XLoadingIcon />
  </div>
  <div class="outer" v-else-if="fileid">
    <div v-if="title || description" class="exif-head" @click="editEXIF()">
      <div class="title" v-if="title">{{ title }}</div>
      <div class="description" v-if="description">{{ description }}</div>
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
      <iframe class="fill-block" :src="mapUrl" />
    </div>
  </div>
  <div v-else>
    {{ t('memries', 'Failed to load metadata') }}
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Component } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';

import axios from '@nextcloud/axios';
import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';

import UserConfig from '../mixins/UserConfig';
import AlbumsList from './modal/AlbumsList.vue';
import Cluster from './frame/Cluster.vue';

import EditIcon from 'vue-material-design-icons/Pencil.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import CameraIrisIcon from 'vue-material-design-icons/CameraIris.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';

import * as utils from '../services/utils';
import * as dav from '../services/dav';
import { API } from '../services/API';

import type { IAlbum, IFace, IImageInfo, IPhoto, IExif } from '../types';

interface TopField {
  id?: string;
  title: string;
  subtitle: string[];
  icon: Component;
  href?: string;
  edit?: () => void;
}

export default defineComponent({
  name: 'Metadata',
  components: {
    NcActions,
    NcActionButton,
    AlbumsList,
    Cluster,
    EditIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    fileid: null as number | null,
    filename: '',
    exif: {} as IExif,
    baseInfo: {} as IImageInfo,

    loading: 0,
    state: 0,
  }),

  mounted() {
    utils.bus.on('files:file:updated', this.handleFileUpdated);
    utils.bus.on('memories:albums:update', this.refresh);
  },

  beforeDestroy() {
    utils.bus.off('files:file:updated', this.handleFileUpdated);
    utils.bus.off('memories:albums:update', this.refresh);
  },

  computed: {
    topFields(): TopField[] {
      let list: TopField[] = [];

      if (this.dateOriginal) {
        list.push({
          title: this.dateOriginalStr!,
          subtitle: this.dateOriginalTime!,
          icon: CalendarIcon,
          edit: this.editDate,
        });
      }

      if (this.camera) {
        list.push({
          title: this.camera,
          subtitle: this.cameraSub,
          icon: CameraIrisIcon,
        });
      }

      if (this.imageInfoTitle) {
        list.push({
          id: 'image-info', // adds class
          title: this.imageInfoTitle,
          subtitle: this.imageInfoSub,
          icon: ImageIcon,
          href: this.filepath
            ? dav.viewInFolderUrl({
                fileid: this.fileid!,
                filename: this.filepath,
              })
            : undefined,
        });
      }

      if (this.tagNamesStr) {
        list.push({
          title: this.tagNamesStr,
          subtitle: [],
          icon: TagIcon,
          edit: this.editTags,
        });
      }

      if (this.address || this.canEdit) {
        list.push({
          title: this.address || this.t('memories', 'No coordinates'),
          subtitle: this.address ? [] : [this.t('memories', 'Click edit to set location')],
          icon: LocationIcon,
          href: this.address ? this.mapFullUrl : undefined,
          edit: this.editGeo,
        });
      }

      return list;
    },

    canEdit(): boolean {
      return this.baseInfo?.permissions?.includes('U');
    },

    /** Title EXIF value */
    title(): string | null {
      return this.exif.Title || null;
    },

    /** Description EXIF value */
    description(): string | null {
      return this.exif.Description || null;
    },

    /** Date taken info */
    dateOriginal(): DateTime | null {
      const epoch = this.exif.DateTimeEpoch || this.baseInfo.datetaken;
      let date = DateTime.fromSeconds(epoch);
      if (!epoch || !date.isValid) return null;

      // The fallback to datetaken can be eventually removed
      // and then this can be discarded
      if (this.exif.DateTimeEpoch) {
        const tzOffset = this.exif.OffsetTimeOriginal || this.exif.OffsetTime; // e.g. -05:00
        const tzId = this.exif.LocationTZID; // e.g. America/New_York

        let dateWithTz: DateTime | undefined = undefined;

        // If no timezone info is available, we will show the local time only
        // In this case, everything happens in UTC
        if (!tzOffset && !tzId) {
          dateWithTz = date.setZone('UTC');
        }

        // Use timezone offset if available
        if (!dateWithTz?.isValid && tzOffset) {
          dateWithTz = date.setZone('UTC' + tzOffset);
        }

        // Fall back to tzId
        if (!dateWithTz?.isValid && tzId) {
          dateWithTz = date.setZone(tzId);
        }

        // Use the timezone only if the date is valid
        if (dateWithTz?.isValid) {
          date = dateWithTz;
        }
      }

      return date;
    },

    dateOriginalStr(): string | null {
      return utils.getLongDateStr(new Date(this.baseInfo.datetaken * 1000), true);
    },

    dateOriginalTime(): string[] | null {
      if (!this.dateOriginal) return null;

      const fields: (keyof IExif)[] = ['OffsetTimeOriginal', 'OffsetTime', 'LocationTZID'];
      const hasTz = fields.some((key) => this.exif[key]);

      const format = 't' + (hasTz ? ' ZZ' : '');

      return [this.dateOriginal.toFormat(format, { locale: getCanonicalLocale() })];
    },

    /** Camera make and model info */
    camera(): string | null {
      const make = this.exif.Make;
      const model = this.exif.Model;
      if (!make || !model) return null;
      if (model.startsWith(make)) return model;
      return `${make} ${model}`;
    },

    cameraSub(): string[] {
      const f = this.exif.FNumber || this.exif.Aperture;
      const s = this.shutterSpeed;
      const len = this.exif.FocalLength;
      const iso = this.exif.ISO;

      const parts: string[] = [];
      if (f) parts.push(`f/${f}`);
      if (s) parts.push(`${s}`);
      if (len) parts.push(`${len}mm`);
      if (iso) parts.push(`ISO${iso}`);
      return parts;
    },

    /** Convert shutter speed decimal to 1/x format */
    shutterSpeed(): string | null {
      const speed = Number(this.exif.ShutterSpeedValue || this.exif.ShutterSpeed || this.exif.ExposureTime);
      if (!speed) return null;

      if (speed < 1) {
        return `1/${Math.round(1 / speed)}`;
      } else {
        return `${Math.round(speed * 10) / 10}s`;
      }
    },

    /** Image info */
    imageInfoTitle(): string | null {
      if (this.config.sidebar_filepath && this.filepath) {
        return this.filepath.replace(/^\//, ''); // remove leading slash
      }

      return this.baseInfo.basename;
    },

    /** Path to file excluding user directory */
    filepath(): string | null {
      return this.baseInfo?.filename ?? null;
    },

    imageInfoSub(): string[] {
      let parts: string[] = [];
      let mp = Number(this.exif.Megapixels);

      if (this.baseInfo.w && this.baseInfo.h) {
        parts.push(`${this.baseInfo.w}x${this.baseInfo.h}`);

        if (!mp) {
          mp = (this.baseInfo.w * this.baseInfo.h) / 1000000;
        }
      }

      if (mp) {
        parts.unshift(`${mp.toFixed(1)}MP`);
      }

      return parts;
    },

    address(): string | undefined {
      if (this.baseInfo.address) {
        return this.baseInfo.address;
      }

      if (this.lat && this.lon) {
        return `${this.lat.toFixed(6)}, ${this.lon.toFixed(6)}`;
      }

      return undefined;
    },

    lat(): number {
      return Number(this.exif.GPSLatitude);
    },

    lon(): number {
      return Number(this.exif.GPSLongitude);
    },

    tagNames(): string[] {
      return Object.values(this.baseInfo?.tags || {}).map((tag: string) => this.t('recognize', tag));
    },

    tagNamesStr(): string | null {
      return this.tagNames.length > 0 ? this.tagNames.join(', ') : null;
    },

    mapUrl(): string {
      const boxSize = 0.0075;
      const bbox = [this.lon - boxSize, this.lat - boxSize, this.lon + boxSize, this.lat + boxSize];
      const m = `${this.lat},${this.lon}`;
      return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox.join()}&marker=${m}`;
    },

    mapFullUrl(): string {
      return `https://www.openstreetmap.org/?mlat=${this.lat}&mlon=${this.lon}#map=18/${this.lat}/${this.lon}`;
    },

    albums(): IAlbum[] {
      return this.baseInfo?.clusters?.albums ?? [];
    },

    people(): IFace[] {
      const clusters = this.baseInfo?.clusters;

      // force face-recognition on its own route, or if recognize is disabled
      if (this.routeIsFaceRecognition || !this.config.recognize_enabled) {
        return clusters?.facerecognition ?? [];
      }

      return clusters?.recognize ?? [];
    },
  },

  methods: {
    async update(photo: number | IPhoto): Promise<IImageInfo | null> {
      this.state = Math.random();
      this.loading = 0;
      this.fileid = null;
      this.exif = {};

      // which clusters to get
      const clusters = this.routeIsPublic
        ? String()
        : [
            this.config.albums_enabled ? 'albums' : null,
            this.config.recognize_enabled ? 'recognize' : null,
            this.config.facerecognition_enabled ? 'facerecognition' : null,
          ]
            .filter((c) => c)
            .join(',');

      // get tags if enabled
      const tags = this.config.systemtags_enabled ? 1 : undefined;

      // get image info
      const url = API.Q(utils.getImageInfoUrl(photo), { tags, clusters });
      const res = await this.guardState(axios.get<IImageInfo>(url));
      if (!res) return null;

      // unwrap basic info
      this.fileid = res.data.fileid;
      this.filename = res.data.basename;
      this.exif = res.data.exif || {};
      this.baseInfo = res.data;

      return this.baseInfo;
    },

    editDate() {
      globalThis.editMetadata([globalThis.currentViewerPhoto], [1]);
    },

    editTags() {
      globalThis.editMetadata([globalThis.currentViewerPhoto], [2]);
    },

    editEXIF() {
      globalThis.editMetadata([globalThis.currentViewerPhoto], [3]);
    },

    editGeo() {
      globalThis.editMetadata([globalThis.currentViewerPhoto], [4]);
    },

    async refresh() {
      if (this.fileid) await this.update(this.fileid);
    },

    async guardState<T>(promise: Promise<T>): Promise<T | null> {
      const state = this.state;
      try {
        this.loading++;
        const res = await promise;
        if (state === this.state) return res;
        return null;
      } catch (err) {
        throw err;
      } finally {
        if (state === this.state) this.loading--;
      }
    },

    handleFileUpdated({ fileid }: utils.BusEvent['files:file:updated']) {
      if (fileid && this.fileid === fileid) {
        this.refresh();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.section-title {
  font-variant: all-small-caps;
  padding: 0px 6px;
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
  :deep .line-one__title {
    font-weight: 400 !important; // no bold title
  }
}

.top-field {
  margin: 10px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;

  .icon,
  .edit {
    display: inline-block;
    margin-right: 10px;

    :deep .material-design-icon {
      color: var(--color-text-lighter);
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
}
</style>
