<template>
  <div class="album-top-matter">
    <NcActions v-if="!isAlbumList">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t("memories", "Back") }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name }}</div>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton
          :aria-label="t('memories', 'Create new album')"
          @click="$refs.createModal.open(false)"
          close-after-click
          v-if="isAlbumList"
        >
          {{ t("memories", "Create new album") }}
          <template #icon> <PlusIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Share album')"
          @click="$refs.shareModal.open(false)"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t("memories", "Share album") }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Edit album details')"
          @click="$refs.createModal.open(true)"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t("memories", "Edit album details") }}
          <template #icon> <EditIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Delete album')"
          @click="$refs.deleteModal.open()"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t("memories", "Delete album") }}
          <template #icon> <DeleteIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <AlbumCreateModal ref="createModal" />
    <AlbumDeleteModal ref="deleteModal" />
    <AlbumShareModal ref="shareModal" />
  </div>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";
import UserConfig from "../../mixins/UserConfig";

import { NcActions, NcActionButton, NcActionCheckbox } from "@nextcloud/vue";
import { getCurrentUser } from "@nextcloud/auth";

import AlbumCreateModal from "../modal/AlbumCreateModal.vue";
import AlbumDeleteModal from "../modal/AlbumDeleteModal.vue";
import AlbumShareModal from "../modal/AlbumShareModal.vue";

import BackIcon from "vue-material-design-icons/ArrowLeft.vue";
import EditIcon from "vue-material-design-icons/Pencil.vue";
import DeleteIcon from "vue-material-design-icons/Close.vue";
import PlusIcon from "vue-material-design-icons/Plus.vue";
import ShareIcon from "vue-material-design-icons/ShareVariant.vue";

@Component({
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,

    AlbumCreateModal,
    AlbumDeleteModal,
    AlbumShareModal,

    BackIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
  },
})
export default class AlbumTopMatter extends Mixins(GlobalMixin, UserConfig) {
  private name: string = "";

  get isAlbumList() {
    return !Boolean(this.$route.params.name);
  }

  get canEditAlbum() {
    return (
      !this.isAlbumList && this.$route.params.user === getCurrentUser()?.uid
    );
  }

  @Watch("$route")
  async routeChange(from: any, to: any) {
    this.createMatter();
  }

  mounted() {
    this.createMatter();
  }

  createMatter() {
    this.name = this.$route.params.name || this.t("memories", "Albums");
  }

  back() {
    this.$router.push({ name: "albums" });
  }
}
</script>

<style lang="scss" scoped>
.album-top-matter {
  display: flex;
  vertical-align: middle;

  .name {
    font-size: 1.3em;
    font-weight: 400;
    line-height: 40px;
    padding-left: 3px;
    flex-grow: 1;
  }

  .right-actions {
    margin-right: 40px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }
  }
}
</style>