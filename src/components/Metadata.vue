<template>
  <div class="outer">
    <div class="top-field" v-for="field of topFields" :key="field.title">
      <div class="icon">
        <component :is="field.icon" :size="24" />
      </div>
      <div class="text">
        {{ field.title }} <br />
        <span class="subtitle">
          <span class="part" v-for="part in field.subtitle" :key="part">
            {{ part }}
          </span>
        </span>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Mixins } from "vue-property-decorator";
import GlobalMixin from "../mixins/GlobalMixin";

import { generateUrl } from "@nextcloud/router";
import axios from "@nextcloud/axios";
import moment from "moment";
import * as utils from "../services/Utils";

import CalendarIcon from "vue-material-design-icons/Calendar.vue";
import CameraIrisIcon from "vue-material-design-icons/CameraIris.vue";

import { IFileInfo } from "../types";
import { getCanonicalLocale } from "@nextcloud/l10n";

@Component({
  components: {},
})
export default class Metadata extends Mixins(GlobalMixin) {
  private exif: { [prop: string]: any } = {};

  public async update(fileInfo: IFileInfo) {
    this.exif = {};
    const res = await axios.get<any>(
      generateUrl("/apps/memories/api/info/{id}", { id: fileInfo.id })
    );
    this.exif = res.data.exif || {};
  }

  get topFields() {
    let list: {
      title: string;
      subtitle: string[];
      icon: any;
    }[] = [];

    if (this.dateOriginal) {
      list.push({
        title: this.dateOriginalStr,
        subtitle: this.dateOriginalTime,
        icon: CalendarIcon,
      });
    }

    if (this.camera) {
      list.push({
        title: this.camera,
        subtitle: this.cameraSub,
        icon: CameraIrisIcon,
      });
    }

    return list;
  }

  get dateOriginal() {
    const dt = this.exif["DateTimeOriginal"] || this.exif["CreateDate"];
    if (!dt) return null;

    const m = moment(dt, "YYYY:MM:DD HH:mm:ss");
    if (!m.isValid()) return null;
    m.locale(getCanonicalLocale());
    return m;
  }

  get dateOriginalStr() {
    if (!this.dateOriginal) return null;
    return utils.getLongDateStr(this.dateOriginal.toDate(), true);
  }

  get dateOriginalTime() {
    if (!this.dateOriginal) return null;

    // Try to get timezone
    let tz = this.exif["OffsetTimeOriginal"] || this.exif["OffsetTime"];
    tz = tz ? "GMT" + tz : "";

    let parts = [];
    parts.push(this.dateOriginal.format("h:mm A"));
    if (tz) parts.push(tz);

    return parts;
  }

  get camera() {
    const make = this.exif["Make"];
    const model = this.exif["Model"];
    if (!make || !model) return null;
    return `${make} ${model}`;
  }

  get cameraSub() {
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
  }

  /** Convert shutter speed decimal to 1/x format */
  get shutterSpeed() {
    const speed = Number(
      this.exif["ShutterSpeedValue"] ||
        this.exif["ShutterSpeed"] ||
        this.exif["ExposureTime"]
    );
    if (!speed) return null;

    if (speed < 1) {
      return `1/${Math.round(1 / speed)}`;
    } else {
      return `${Math.round(speed * 10) / 10}`;
    }
  }
}
</script>

<style lang="scss" scoped>
.top-field {
  margin: 10px;
  margin-bottom: 25px;

  .icon {
    display: inline-block;
    vertical-align: middle;
    margin-right: 10px;
    color: var(--color-text-lighter);
  }
  .text {
    display: inline-block;
    vertical-align: middle;

    .subtitle {
      font-size: 0.95em;
    }
  }
}
</style>