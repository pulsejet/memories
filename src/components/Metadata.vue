<template>
  <div class="outer">
    <div v-for="prop of props" :key="prop">
      <div class="prop">{{ prop }}</div>
      <div class="value">{{ exif[prop] }}</div>
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Mixins } from "vue-property-decorator";
import GlobalMixin from "../mixins/GlobalMixin";

import { generateUrl } from "@nextcloud/router";
import axios from "@nextcloud/axios";

import { IFileInfo } from "../types";

@Component({
  components: {},
})
export default class Metadata extends Mixins(GlobalMixin) {
  private exif: { [prop: string]: any } = {};

  get props() {
    return Object.keys(this.exif);
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