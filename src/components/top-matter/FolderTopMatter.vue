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
      <NcActions :inline="2">
        <NcActionRouter
          :to="{...this.$route, query: {recursive: recursive ? undefined : '1'}}"
          close-after-click
        >
          {{ t("memories", recursive ? "Show folders" : "Timeline") }}
          <template #icon>
            <TimelineIcon v-if="recursive" :size="20"/>
            <FoldersIcon v-else :size="20"/>
          </template>
        </NcActionRouter>
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
import { Component, Mixins, Watch } from "vue-property-decorator";
import { TopMatterFolder, TopMatterType } from "../../types";

const NcBreadcrumbs = () =>
  import("@nextcloud/vue/dist/Components/NcBreadcrumbs");
const NcBreadcrumb = () =>
  import("@nextcloud/vue/dist/Components/NcBreadcrumb");
import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";
import NcActionRouter from "@nextcloud/vue/dist/Components/NcActionRouter";

import GlobalMixin from "../../mixins/GlobalMixin";

import FolderShareModal from "../modal/FolderShareModal.vue";

import HomeIcon from "vue-material-design-icons/Home.vue";
import ShareIcon from "vue-material-design-icons/ShareVariant.vue";
import TimelineIcon from "vue-material-design-icons/ImageMultiple.vue";
import FoldersIcon from "vue-material-design-icons/FolderMultiple.vue";

@Component({
  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    NcActionRouter,
    FolderShareModal,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon
  },
})
export default class FolderTopMatter extends Mixins(GlobalMixin) {
  private topMatter?: TopMatterFolder = null;
  private recursive: boolean = false;

  @Watch("$route")
  async routeChange(from: any, to: any) {
    this.createMatter();
  }

  mounted() {
    this.createMatter();
  }

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
      this.recursive = this.$route.query.recursive === '1'
    } else {
      this.topMatter = null;
      this.recursive = false;
    }
  }
}
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