<template>
  <div class="outer" v-if="fileid">
    <div v-if="albums.length">
      <div class="section-title">{{ t('memories', 'Albums') }}</div>
      <AlbumsList class="albums" :albums="albums" />
    </div>

    <div class="section-title">{{ t('memories', 'Metadata') }}</div>
    <div class="top-field" v-for="field of topFields" :key="field.title">
      <div class="icon">
        <component :is="field.icon" :size="24" />
      </div>

      <div class="text">
        <template v-if="field.href">
          <a :href="field.href" target="_blank" rel="noopener noreferrer">
            {{ field.title }}
          </a>
        </template>
        <template v-else>
          {{ field.title }}
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

  <div class="loading-icon fill-block" v-else>
    <XLoadingIcon />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';

import axios from '@nextcloud/axios';
import { subscribe, unsubscribe } from '@nextcloud/event-bus';
import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';

import AlbumsList from './modal/AlbumsList.vue';

import EditIcon from 'vue-material-design-icons/Pencil.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import CameraIrisIcon from 'vue-material-design-icons/CameraIris.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import InfoIcon from 'vue-material-design-icons/InformationOutline.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';

import * as utils from '../services/Utils';
import { API } from '../services/API';
import router from '../router';

import type { IAlbum, IImageInfo, IPhoto } from '../types';

interface TopField {
  title: string;
  subtitle: string[];
  icon: any;
  href?: string;
  edit?: () => void;
}

export default defineComponent({
  name: 'Metadata',
  components: {
    NcActions,
    NcActionButton,
    EditIcon,
    AlbumsList,
  },

  data: () => ({
    fileid: null as number | null,
    filename: '',
    exif: {} as { [prop: string]: any },
    baseInfo: {} as IImageInfo,
    albums: [] as IAlbum[],
    state: 0,
  }),

  mounted() {
    subscribe('files:file:updated', this.handleFileUpdated);
    subscribe('memories:albums:update', this.refreshAlbums);
  },

  beforeDestroy() {
    unsubscribe('files:file:updated', this.handleFileUpdated);
    unsubscribe('memories:albums:update', this.refreshAlbums);
  },

  computed: {
    topFields(): TopField[] {
      let list: TopField[] = [];

      if (this.dateOriginal) {
        list.push({
          title: this.dateOriginalStr!,
          subtitle: this.dateOriginalTime!,
          icon: CalendarIcon,
          edit: () => globalThis.editMetadata([globalThis.currentViewerPhoto], [1]),
        });
      }

      if (this.camera) {
        list.push({
          title: this.camera,
          subtitle: this.cameraSub,
          icon: CameraIrisIcon,
        });
      }

      if (this.imageInfo) {
        list.push({
          title: this.imageInfo,
          subtitle: this.imageInfoSub,
          icon: ImageIcon,
        });
      }

      const title = this.exif?.['Title'];
      const desc = this.exif?.['Description'];
      if (title || desc) {
        list.push({
          title: title || this.t('memories', 'No title'),
          subtitle: [desc || this.t('memories', 'No description')],
          icon: InfoIcon,
          edit: () => globalThis.editMetadata([globalThis.currentViewerPhoto], [3]),
        });
      }

      if (this.tagNamesStr) {
        list.push({
          title: this.tagNamesStr,
          subtitle: [],
          icon: TagIcon,
          edit: () => globalThis.editMetadata([globalThis.currentViewerPhoto], [2]),
        });
      }

      if (this.address || this.canEdit) {
        list.push({
          title: this.address || this.t('memories', 'No coordinates'),
          subtitle: this.address ? [] : [this.t('memories', 'Click edit to set location')],
          icon: LocationIcon,
          href: this.address ? this.mapFullUrl : undefined,
          edit: () => globalThis.editMetadata([globalThis.currentViewerPhoto], [4]),
        });
      }

      return list;
    },

    canEdit(): boolean {
      return this.baseInfo?.permissions?.includes('U');
    },

    /** Date taken info */
    dateOriginal(): DateTime | null {
      const epoch = this.exif.DateTimeEpoch || this.baseInfo.datetaken;
      let date = DateTime.fromSeconds(epoch);
      if (!epoch || !date.isValid) return null;

      // The fallback to datetaken can be eventually removed
      // and then this can be discarded
      if (this.exif.DateTimeEpoch) {
        const tzOffset: string = this.exif.OffsetTimeOriginal || this.exif.OffsetTime; // e.g. -05:00
        const tzId: string = this.exif.LocationTZID; // e.g. America/New_York

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

      const fields = ['OffsetTimeOriginal', 'OffsetTime', 'LocationTZID'];
      const hasTz = fields.some((key) => this.exif[key]);

      const format = 't' + (hasTz ? ' ZZ' : '');

      return [this.dateOriginal.toFormat(format, { locale: getCanonicalLocale() })];
    },

    /** Camera make and model info */
    camera(): string | null {
      const make = this.exif['Make'];
      const model = this.exif['Model'];
      if (!make || !model) return null;
      if (model.startsWith(make)) return model;
      return `${make} ${model}`;
    },

    cameraSub(): string[] {
      const f = this.exif['FNumber'] || this.exif['Aperture'];
      const s = this.shutterSpeed;
      const len = this.exif['FocalLength'];
      const iso = this.exif['ISO'];

      const parts: string[] = [];
      if (f) parts.push(`f/${f}`);
      if (s) parts.push(`${s}`);
      if (len) parts.push(`${len}mm`);
      if (iso) parts.push(`ISO${iso}`);
      return parts;
    },

    /** Convert shutter speed decimal to 1/x format */
    shutterSpeed(): string | null {
      const speed = Number(this.exif['ShutterSpeedValue'] || this.exif['ShutterSpeed'] || this.exif['ExposureTime']);
      if (!speed) return null;

      if (speed < 1) {
        return `1/${Math.round(1 / speed)}`;
      } else {
        return `${Math.round(speed * 10) / 10}s`;
      }
    },

    /** Image info */
    imageInfo(): string | null {
      return this.baseInfo.basename;
    },

    imageInfoSub(): string[] {
      let parts: string[] = [];
      let mp = Number(this.exif['Megapixels']);

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
      return Number(this.exif['GPSLatitude']);
    },

    lon(): number {
      return Number(this.exif['GPSLongitude']);
    },

    tagNames(): string[] {
      return Object.values(this.baseInfo?.tags || {});
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
  },

  methods: {
    async update(photo: number | IPhoto): Promise<IImageInfo> {
      this.state = Math.random();
      this.fileid = null;
      this.exif = {};
      this.albums = [];

      const state = this.state;
      const url = API.Q(utils.getImageInfoUrl(photo), { tags: 1 });
      const res = await axios.get<IImageInfo>(url);
      if (state !== this.state) return res.data;

      this.fileid = res.data.fileid;
      this.filename = res.data.basename;
      this.exif = res.data.exif || {};
      this.baseInfo = res.data;

      // trigger other refreshes
      this.refreshAlbums();

      return this.baseInfo;
    },

    async refreshAlbums(): Promise<IAlbum[]> {
      const state = this.state;

      // get album list
      let list: IAlbum[] = [];
      try {
        list = (await axios.get<IAlbum[]>(API.ALBUM_LIST(3, this.fileid!))).data;
      } catch (e) {
        console.error('metadata: failed to load albums', e);
      }

      // filter albums containing this file
      list = list.filter((a) => a.has_file);

      if (state !== this.state) return list;
      return (this.albums = list);
    },

    handleFileUpdated({ fileid }: { fileid: number }) {
      if (fileid && this.fileid === fileid) {
        this.update(this.fileid);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.section-title {
  font-variant: all-small-caps;
  padding: 0px 10px;
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
