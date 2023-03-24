<template>
  <div class="grid" v-if="items.length">
    <div class="grid-item fill-block" v-for="item of items" :key="item.fileid">
      <Folder :data="item" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import axios from "@nextcloud/axios";

import UserConfig from "../mixins/UserConfig";
import Folder from "./frame/Folder.vue";

import * as utils from "../services/Utils";
import { IFolder } from "../types";
import { API } from "../services/API";

export default defineComponent({
  name: "ClusterGrid",

  components: {
    Folder,
  },

  mixins: [UserConfig],

  data: () => ({
    items: [] as IFolder[],
    path: "",
  }),

  mounted() {
    this.refresh();
  },

  watch: {
    $route() {
      this.items = [];
      this.refresh();
    },
    config_showHidden() {
      this.refresh();
    },
  },

  methods: {
    async refresh() {
      // Get folder path
      const folder = (this.path = utils.getFolderRoutePath(
        this.config_foldersPath
      ));

      // Get subfolders for this folder
      const res = await axios.get<IFolder[]>(
        API.Q(API.FOLDERS_SUB(), { folder })
      );
      if (folder !== this.path) return;
      this.items = res.data;
      this.$emit("load", this.items);

      // Filter out hidden folders
      if (!this.config_showHidden) {
        this.items = this.items.filter(
          (f) => !f.name.startsWith(".") && f.previews.length
        );
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100% / 3, 165px), 1fr));

  width: calc(100% - 40px); // leave space for scroller
  @media (max-width: 768px) {
    width: calc(100% - 2px); // compensation for negative margin
  }

  .grid-item {
    aspect-ratio: 1 / 1;
    position: relative;
  }
}
</style>
