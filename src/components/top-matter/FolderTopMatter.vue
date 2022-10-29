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
import { Component, Mixins, Watch } from "vue-property-decorator";
import { TopMatterFolder, TopMatterType } from "../../types";
import {
  NcBreadcrumbs,
  NcBreadcrumb,
  NcActions,
  NcActionButton,
} from "@nextcloud/vue";
import GlobalMixin from "../../mixins/GlobalMixin";

import FolderShareModal from "../modal/FolderShareModal.vue";

import HomeIcon from "vue-material-design-icons/Home.vue";
import ShareIcon from "vue-material-design-icons/ShareVariant.vue";

@Component({
  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    FolderShareModal,
    HomeIcon,
    ShareIcon,
  },
})
export default class FolderTopMatter extends Mixins(GlobalMixin) {
  private topMatter?: TopMatterFolder = null;

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
    } else {
      this.topMatter = null;
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