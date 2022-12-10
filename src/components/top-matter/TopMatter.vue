<template>
  <div class="top-matter" v-if="type">
    <FolderTopMatter v-if="type === 1" />
    <TagTopMatter v-else-if="type === 2" />
    <FaceTopMatter v-else-if="type === 3" />
    <AlbumTopMatter v-else-if="type === 4" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import FolderTopMatter from "./FolderTopMatter.vue";
import TagTopMatter from "./TagTopMatter.vue";
import FaceTopMatter from "./FaceTopMatter.vue";
import AlbumTopMatter from "./AlbumTopMatter.vue";

import { TopMatterType } from "../../types";

export default defineComponent({
  name: "TopMatter",
  components: {
    FolderTopMatter,
    TagTopMatter,
    FaceTopMatter,
    AlbumTopMatter,
  },

  data: () => ({
    type: TopMatterType.NONE,
  }),

  watch: {
    $route: function (from: any, to: any) {
      this.setTopMatter();
    },
  },

  mounted() {
    this.setTopMatter();
  },

  methods: {
    /** Create top matter */
    setTopMatter() {
      this.type = (() => {
        switch (this.$route.name) {
          case "folders":
            return TopMatterType.FOLDER;
          case "tags":
            return this.$route.params.name
              ? TopMatterType.TAG
              : TopMatterType.NONE;
          case "recognize":
          case "facerecognition":
            return this.$route.params.name
              ? TopMatterType.FACE
              : TopMatterType.NONE;
          case "albums":
            return TopMatterType.ALBUM;
          default:
            return TopMatterType.NONE;
        }
      })();
    },
  },
});
</script>