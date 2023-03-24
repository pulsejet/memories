<template>
  <div class="top-matter-container" v-if="type">
    <FolderTopMatter v-if="type === 1" />
    <ClusterTopMatter v-else-if="type === 2" />
    <FaceTopMatter v-else-if="type === 3" />
    <AlbumTopMatter v-else-if="type === 4" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import FolderTopMatter from "./FolderTopMatter.vue";
import ClusterTopMatter from "./ClusterTopMatter.vue";
import FaceTopMatter from "./FaceTopMatter.vue";
import AlbumTopMatter from "./AlbumTopMatter.vue";

import { TopMatterType } from "../../types";

export default defineComponent({
  name: "TopMatter",
  components: {
    FolderTopMatter,
    ClusterTopMatter,
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
          case "albums":
            return TopMatterType.ALBUM;
          case "tags":
          case "places":
            return TopMatterType.CLUSTER;
          case "recognize":
          case "facerecognition":
            return this.$route.params.name
              ? TopMatterType.FACE
              : TopMatterType.CLUSTER;
          default:
            return TopMatterType.NONE;
        }
      })();
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter-container {
  padding-top: 4px;
  @media (max-width: 768px) {
    padding-left: 40px;
  }

  > div {
    display: flex;
    vertical-align: middle;
  }

  :deep .name {
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    vertical-align: top;
    flex-grow: 1;
    padding-left: 10px;
  }

  :deep button + .name {
    padding-left: 0;
  }

  :deep .right-actions {
    margin-right: 40px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }

    span {
      cursor: pointer;
    }
  }

  :deep button {
    display: inline-block;
  }
}
</style>
