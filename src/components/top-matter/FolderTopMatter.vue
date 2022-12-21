<template>
  <div class="top-matter">
    <NcBreadcrumbs v-if="topMatter">
      <NcBreadcrumb title="Home" :to="{ name: 'folders' }">
        <template #icon>
          <HomeIcon :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb
        v-for="folder in topMatter.list"
        :key="folder.path"
        :title="folder.text"
        :to="{ name: 'folders', params: { path: folder.path } }"
      />
    </NcBreadcrumbs>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton
          :aria-label="t('memories', 'Share folder')"
          @click="$refs.shareModal.open(false)"
          close-after-click
        >
          {{ t("memories", "Share folder") }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <FolderShareModal ref="shareModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { TopMatterFolder, TopMatterType } from "../../types";

const NcBreadcrumbs = () =>
  import("@nextcloud/vue/dist/Components/NcBreadcrumbs");
const NcBreadcrumb = () =>
  import("@nextcloud/vue/dist/Components/NcBreadcrumb");
import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";

import FolderShareModal from "../modal/FolderShareModal.vue";

import HomeIcon from "vue-material-design-icons/Home.vue";
import ShareIcon from "vue-material-design-icons/ShareVariant.vue";

export default defineComponent({
  name: "FolderTopMatter",
  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    FolderShareModal,
    HomeIcon,
    ShareIcon,
  },

  data: () => ({
    topMatter: null as TopMatterFolder | null,
  }),

  watch: {
    $route: function (from: any, to: any) {
      this.createMatter();
    },
  },

  mounted() {
    this.createMatter();
  },

  methods: {
    createMatter() {
      if (this.$route.name === "folders") {
        let path: any = this.$route.params.path || "";
        if (typeof path === "string") {
          path = path.split("/");
        }

        this.topMatter = {
          type: TopMatterType.FOLDER,
          list: path
            .filter((x) => x)
            .map((x, idx, arr) => {
              return {
                text: x,
                path: arr.slice(0, idx + 1).join("/"),
              };
            }),
        };
      } else {
        this.topMatter = null;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  display: flex;
  vertical-align: middle;

  .right-actions {
    margin-right: 40px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }
  }
}
</style>