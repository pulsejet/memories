<template>
  <div class="outer" v-if="fileInfo">
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
          <NcActionButton
            :aria-label="t('memories', 'Edit')"
            @click="field.edit()"
          >
            {{ t("memories", "Edit") }}
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
    <NcLoadingIcon />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";

import axios from "@nextcloud/axios";
import { subscribe, unsubscribe } from "@nextcloud/event-bus";
import { getCanonicalLocale } from "@nextcloud/l10n";
import moment from "moment";

import * as utils from "../services/Utils";

import { IFileInfo } from "../types";

import EditIcon from "vue-material-design-icons/Pencil.vue";
import CalendarIcon from "vue-material-design-icons/Calendar.vue";
import CameraIrisIcon from "vue-material-design-icons/CameraIris.vue";
import ImageIcon from "vue-material-design-icons/Image.vue";
import InfoIcon from "vue-material-design-icons/InformationOutline.vue";
import LocationIcon from "vue-material-design-icons/MapMarker.vue";
import TagIcon from "vue-material-design-icons/Tag.vue";
import { API } from "../services/API";

interface TopField {
  title: string;
  subtitle: string[];
  icon: any;
  href?: string;
  edit?: () => void;
}

export default defineComponent({
  name: "Metadata",
  components: {
    NcActions,
    NcActionButton,
    NcLoadingIcon,
    EditIcon,
  },

  data: () => ({
    fileInfo: null as IFileInfo,
    exif: {} as { [prop: string]: any },
    baseInfo: {} as any,
    state: 0,
  }),

  mounted() {
    subscribe("files:file:updated", this.handleFileUpdated);
  },

  beforeDestroy() {
    unsubscribe("files:file:updated", this.handleFileUpdated);
  },

  computed: {
    topFields(): TopField[] {
      let list: TopField[] = [];

      if (this.dateOriginal) {
        list.push({
          title: this.dateOriginalStr,
          subtitle: this.dateOriginalTime,
          icon: CalendarIcon,
          edit: () =>
            globalThis.editMetadata([globalThis.currentViewerPhoto], [1]),
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

      const title = this.exif?.["Title"];
      const desc = this.exif?.["Description"];
      if (title || desc) {
        list.push({
          title: title || this.t("memories", "No title"),
          subtitle: [desc || this.t("memories", "No description")],
          icon: InfoIcon,
          edit: () =>
            globalThis.editMetadata([globalThis.currentViewerPhoto], [3]),
        });
      }

      if (this.tagNamesStr) {
        list.push({
          title: this.tagNamesStr,
          subtitle: [],
          icon: TagIcon,
          edit: () =>
            globalThis.editMetadata([globalThis.currentViewerPhoto], [2]),
        });
      }

      list.push({
        title: this.address || this.t("memories", "No coordinates"),
        subtitle: this.address
          ? []
          : [this.t("memories", "Click edit to set location")],
        icon: LocationIcon,
        href: this.address ? this.mapFullUrl : undefined,
        edit: () =>
          globalThis.editMetadata([globalThis.currentViewerPhoto], [4]),
      });

      return list;
    },

    canEdit(): boolean {
      return this.baseInfo?.permissions?.includes("U");
    },

    /** Date taken info */
    dateOriginal(): moment.Moment | null {
      const dt = this.exif["DateTimeOriginal"] || this.exif["CreateDate"];
      if (!dt) return null;

      const m = moment.utc(dt, "YYYY:MM:DD HH:mm:ss");
      if (!m.isValid()) return null;
      m.locale(getCanonicalLocale());
      return m;
    },

    dateOriginalStr(): string | null {
      if (!this.dateOriginal) return null;
      return utils.getLongDateStr(this.dateOriginal.toDate(), true);
    },

    dateOriginalTime(): string[] | null {
      if (!this.dateOriginal) return null;

      // Try to get timezone
      let tz = this.exif["OffsetTimeOriginal"] || this.exif["OffsetTime"];
      tz = tz ? "GMT" + tz : "";

      let parts = [];
      parts.push(this.dateOriginal.format("h:mm A"));
      if (tz) parts.push(tz);

      return parts;
    },

    /** Camera make and model info */
    camera(): string | null {
      const make = this.exif["Make"];
      const model = this.exif["Model"];
      if (!make || !model) return null;
      if (model.startsWith(make)) return model;
      return `${make} ${model}`;
    },

    cameraSub(): string[] | null {
      const f = this.exif["FNumber"] || this.exif["Aperture"];
      const s = this.shutterSpeed;
      const len = this.exif["FocalLength"];
      const iso = this.exif["ISO"];

      const parts = [];
      if (f) parts.push(`f/${f}`);
      if (s) parts.push(`${s}`);
      if (len) parts.push(`${len}mm`);
      if (iso) parts.push(`ISO${iso}`);
      return parts;
    },

    /** Convert shutter speed decimal to 1/x format */
    shutterSpeed(): string | null {
      const speed = Number(
        this.exif["ShutterSpeedValue"] ||
          this.exif["ShutterSpeed"] ||
          this.exif["ExposureTime"]
      );
      if (!speed) return null;

      if (speed < 1) {
        return `1/${Math.round(1 / speed)}`;
      } else {
        return `${Math.round(speed * 10) / 10}s`;
      }
    },

    /** Image info */
    imageInfo(): string | null {
      return (
        this.fileInfo?.originalBasename ||
        this.fileInfo?.basename ||
        (<any>this.fileInfo)?.name
      );
    },

    imageInfoSub(): string[] | null {
      let parts = [];
      let mp = Number(this.exif["Megapixels"]);

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

    address(): string | null {
      return this.baseInfo.address;
    },

    lat(): number {
      return this.exif["GPSLatitude"];
    },

    lon(): number {
      return this.exif["GPSLongitude"];
    },

    tagNames(): string[] {
      return Object.values(this.baseInfo?.tags || {});
    },

    tagNamesStr(): string {
      return this.tagNames.length > 0 ? this.tagNames.join(", ") : null;
    },

    mapUrl(): string | null {
      const boxSize = 0.0075;
      const bbox = [
        this.lon - boxSize,
        this.lat - boxSize,
        this.lon + boxSize,
        this.lat + boxSize,
      ];
      const m = `${this.lat},${this.lon}`;
      return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox.join()}&marker=${m}`;
    },

    mapFullUrl(): string | null {
      return `https://www.openstreetmap.org/?mlat=${this.lat}&mlon=${this.lon}#map=18/${this.lat}/${this.lon}`;
    },
  },

  methods: {
    async update(fileInfo: IFileInfo) {
      this.state = Math.random();
      this.fileInfo = null;
      this.exif = {};

      const state = this.state;
      const url = API.Q(API.IMAGE_INFO(fileInfo.fileid), "tags=1");
      const res = await axios.get<any>(url);
      if (state !== this.state) return;

      this.fileInfo = fileInfo;
      this.exif = res.data.exif || {};
      this.baseInfo = res.data;
    },

    handleFileUpdated({ fileid }) {
      if (fileid && this.fileInfo?.id === fileid) {
        this.update(this.fileInfo);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
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
  :deep svg {
    width: 60px;
    height: 60px;
  }
}

.map {
  width: 100%;
  aspect-ratio: 16 / 10;
  min-height: 200px;
  max-height: 250px;
}
</style>
