<template>
  <div class="outer">
    <div class="top-field" v-if="dateOriginal">
      <div class="icon">
        <CalendarIcon :size="24" />
      </div>
      <div class="text">
        {{ dateOriginalStr }} <br />
        <span class="subtitle">
          {{ dateOriginalTime }}
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

import { IFileInfo } from "../types";
import { getCanonicalLocale } from "@nextcloud/l10n";

@Component({
  components: {
    CalendarIcon,
  },
})
export default class Metadata extends Mixins(GlobalMixin) {
  private exif: { [prop: string]: any } = {};

  get props() {
    return Object.keys(this.exif);
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

    return this.dateOriginal.format("h:mm A") + " " + tz;
  }

  public async update(fileInfo: IFileInfo) {
    this.exif = {};
    const res = await axios.get<any>(
      generateUrl("/apps/memories/api/info/{id}", { id: fileInfo.id })
    );
    this.exif = res.data.exif || {};
  }
}
</script>

<style lang="scss" scoped>
.top-field {
  margin: 5px 10px;

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