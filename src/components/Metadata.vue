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
    
    <div v-if="embeddedTags.length > 0" class="top-field top-field--embedded-tags">
      <div class="icon">
        <TagIcon :size="24" />
      </div>

      <div class="text">
        <div class="title">{{ t('memories', 'Tags') }} ({{ embeddedTags.length }})</div>
        <div class="tags-container">
          <template v-for="(tag, idx) in embeddedTags">
            <NcChip v-if="tag.length === 1" :key="`tag-${idx}`" :text="tag[0]" no-close />
            <div v-else-if="tag.length > 1" :key="`taglist-${idx}`" style="display: inline-block; margin: 2px;">
              <NcPopover no-focus-trap>
                <template #trigger>
                  <NcButton>{{ tag[tag.length - 1] }}</NcButton>
                </template>
                <template #default>
                  <div class="tag-path">{{ tag.join(' → ') }}</div>
                </template>
              </NcPopover>
            </div>
          </template>
        </div>
      </div>

      <div class="edit" v-if="canEdit">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Edit')" @click="editTags()">
            {{ t('memories', 'Edit') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div class="top-field top-field--rating">
      <RatingStars :rating="rating ?? 0" :readonly="!canEdit" @update:rating="updateRating" />
    </div>

    <div v-if="lat && lon" class="map">
      <iframe class="fill-block" :src="mapUrl"></iframe>
    </div>
  </div>
  <div class="loading-icon fill-block" v-else-if="loading">
    <XLoadingIcon />
  </div>
  <div v-else-if="error">
    {{ t('memories', 'Failed to load metadata') }}
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Component } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js';
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

const NcAvatar = () => import('@nextcloud/vue/dist/Components/NcAvatar.js');

import axios from '@nextcloud/axios';
import { getCanonicalLocale } from '@nextcloud/l10n';
import { DateTime } from 'luxon';

import UserConfig from '@mixins/UserConfig';
import Cluster from '@components/frame/Cluster.vue';
import AlbumsList from '@components/modal/AlbumsList.vue';
import RatingStars from '@components/RatingStars.vue';

import EditIcon from 'vue-material-design-icons/Pencil.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import CameraIrisIcon from 'vue-material-design-icons/CameraIris.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import { API } from '@services/API';

import type { IAlbum, IFace, IImageInfo, IPhoto, IExif } from '@typings';
import { showError } from '@nextcloud/dialogs';

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
    NcAvatar,
    NcChip,
    NcPopover,
    NcButton,
    AlbumsList,
    Cluster,
    EditIcon,
    TagIcon,
    RatingStars,
  },

  mixins: [UserConfig],

  data: () => ({
    fileid: null as number | null,
    filename: '',
    exif: {} as IExif,
    baseInfo: {} as IImageInfo,
    lock: false,
    error: false,

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
      return this.baseInfo?.permissions?.includes('U') && !this.lock;
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
      // Try to get timezone info
      let dateWithTz: DateTime | null = null;
      const valid = () => dateWithTz?.isValid;

      // If we have an actual epoch, we can shift the date to the correct timezone
      if (!valid() && this.exif.DateTimeEpoch) {
        const date = DateTime.fromSeconds(this.exif.DateTimeEpoch);
        if (date.isValid) {
          const tzOffset = this.exif.OffsetTimeOriginal || this.exif.OffsetTime; // e.g. -05:00
          const tzId = this.exif.LocationTZID; // e.g. America/New_York

          // Use timezone offset if available
          if (!valid() && tzOffset) {
            dateWithTz = date.setZone('UTC' + tzOffset);
          }

          // Fall back to tzId
          if (!valid() && tzId) {
            dateWithTz = date.setZone(tzId);
          }
        }
      }

      // If tz info is unavailable / wrong, we will show the local time only
      // In this case, use the datetaken instead, which is guaranteed to be local, shifted to UTC
      if (!valid() && this.baseInfo.datetaken) {
        const date = DateTime.fromSeconds(this.baseInfo.datetaken);
        if (date.isValid) {
          dateWithTz = date.setZone('UTC');
        }
      }

      // Return only if we found a valid date
      return valid() ? dateWithTz : null;
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

    rating(): number | null {
      return typeof this.exif.Rating === 'number' ? this.exif.Rating : null;
    },

    embeddedTags(): string[][]  {
      const ensureArray = (v: string | string[] | undefined | null) => v ? (Array.isArray(v) ? v : [v]) : undefined;
      return ensureArray(this.exif.TagsList)?.map((tag) => tag.split('/')) || ensureArray(this.exif.HierarchicalSubject)?.map((tag) => tag.split('|')) || ensureArray(this.exif.Keywords)?.map((tag) => [tag]) || ensureArray(this.exif.Subject)?.map((tag) => [tag]) || [];
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
      let albums = this.baseInfo?.clusters?.albums ?? [];

      // Filter out hidden albums
      if (!this.config.show_hidden_albums) {
        albums = albums.filter((a) => !a.name.startsWith('.'));
      }

      return albums;
    },

    people(): IFace[] {
      const clusters = this.baseInfo?.clusters;

      // force face-recognition on its own route, or if recognize is disabled
      if (this.routeIsFaceRecognition || !this.config.recognize_enabled) {
        return clusters?.facerecognition ?? [];
      }

      return clusters?.recognize ?? [];
    },

    isShared(): boolean {
      return !!this.baseInfo.owneruid && this.baseInfo.owneruid !== utils.uid;
    },
  },

  methods: {
    async update(photo: number | IPhoto): Promise<IImageInfo | null> {
      this.invalidateUnless(0);

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

      // set image info
      this.baseInfo = res.data;
      this.fileid = this.baseInfo.fileid;
      this.filename = this.baseInfo.basename;
      this.exif = this.baseInfo.exif ?? {};

      return this.baseInfo;
    },

    async refresh() {
      if (this.fileid) await this.update(this.fileid);
    },

    /**
     * Invalidate metadata for a future change
     * @param fileid Invalidate metadata unless this is the current fileid
     */
    invalidateUnless(fileid: number) {
      if (this.fileid === fileid) return;
      this.state = Math.random();
      this.loading = 0;
      this.error = false;
      this.fileid = null;
      this.exif = {};
    },

    editDate() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [1]);
    },

    editTags() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [2]);
    },

    editEXIF() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [3]);
    },

    editGeo() {
      _m.modals.editMetadata([_m.viewer.currentPhoto!], [4]);
    },

    async updateExif(fileid: number, fields: Partial<IExif>) {
      this.lock = true;
      try {
        const raw = this.exif ?? {};
        //optimistically update the exif
        this.exif = { ...raw, ...fields };
        await axios.patch<IImageInfo>(API.IMAGE_SETEXIF(fileid), { raw: this.exif });
      }
      catch (e) {
        console.error('Failed to save metadata for', fileid, e);
        if (e.response?.data?.message) {
          showError(e.response.data.message);
        } else {
          showError(e);
        }
      } finally {
        this.lock = false;
        utils.bus.emit('files:file:updated', { fileid });
      }
    },

    updateRating(rating: number) {
      this.updateExif(this.fileid!, { Rating: rating === this.exif.Rating ? undefined : rating });
    },

    handleFileUpdated({ fileid }: utils.BusEvent['files:file:updated']) {
      if (fileid && this.fileid === fileid) {
        this.refresh();
      }
    },

    async guardState<T>(promise: Promise<T>): Promise<T | null> {
      const state = this.state;
      try {
        this.loading++;
        const res = await promise;
        if (state === this.state) return res;
        return null;
      } catch (err) {
        this.error = true;
        throw err;
      } finally {
        if (state === this.state) this.loading--;
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
  :deep .line-one__title {
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

.tags-container {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;

  :deep .chip {
    margin: 0;
  }
}

.tag-path {
  padding: 8px 12px;
  font-size: 0.9em;
  white-space: nowrap;
}
</style>
