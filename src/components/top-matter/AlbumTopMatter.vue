<template>
  <div class="top-matter">
    <NcActions v-if="!isAlbumList">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t("memories", "Back") }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name }}</div>

    <div class="right-actions">
      <NcActions :forceMenu="true" v-if="isAlbumList">
        <template #icon>
          <SortIcon :size="20" />
        </template>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Sort by date')"
          :checked="config_albumListSort === 1"
          @change="changeSort(1)"
          close-after-click
        >
          {{ t("memories", "Sort by date") }}
          <template #icon> <SortDateIcon :size="20" /> </template>
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Sort by name')"
          :checked="config_albumListSort === 2"
          @change="changeSort(2)"
          close-after-click
        >
          {{ t("memories", "Sort by name") }}
          <template #icon> <SlotAlphabeticalIcon :size="20" /> </template>
        </NcActionRadio>
      </NcActions>

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
          :aria-label="t('memories', 'Download album')"
          @click="downloadAlbum()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t("memories", "Download album") }}
          <template #icon> <DownloadIcon :size="20" /> </template>
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
import { defineComponent } from "vue";

import UserConfig from "../../mixins/UserConfig";
import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";
import NcActionCheckbox from "@nextcloud/vue/dist/Components/NcActionCheckbox";
import NcActionRadio from "@nextcloud/vue/dist/Components/NcActionRadio";

import { getCurrentUser } from "@nextcloud/auth";
import axios from "@nextcloud/axios";

import AlbumCreateModal from "../modal/AlbumCreateModal.vue";
import AlbumDeleteModal from "../modal/AlbumDeleteModal.vue";
import AlbumShareModal from "../modal/AlbumShareModal.vue";

import { downloadWithHandle } from "../../services/dav/download";

import BackIcon from "vue-material-design-icons/ArrowLeft.vue";
import DownloadIcon from "vue-material-design-icons/Download.vue";
import EditIcon from "vue-material-design-icons/Pencil.vue";
import DeleteIcon from "vue-material-design-icons/Close.vue";
import PlusIcon from "vue-material-design-icons/Plus.vue";
import ShareIcon from "vue-material-design-icons/ShareVariant.vue";
import SortIcon from "vue-material-design-icons/SortVariant.vue";
import SlotAlphabeticalIcon from "vue-material-design-icons/SortAlphabeticalAscending.vue";
import SortDateIcon from "vue-material-design-icons/SortCalendarDescending.vue";
import { API } from "../../services/API";

export default defineComponent({
  name: "AlbumTopMatter",
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionRadio,

    AlbumCreateModal,
    AlbumDeleteModal,
    AlbumShareModal,

    BackIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
    SortIcon,
    SlotAlphabeticalIcon,
    SortDateIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    name: "",
  }),

  computed: {
    isAlbumList(): boolean {
      return !Boolean(this.$route.params.name);
    },

    canEditAlbum(): boolean {
      return (
        !this.isAlbumList && this.$route.params.user === getCurrentUser()?.uid
      );
    },
  },

  watch: {
    $route: async function (from: any, to: any) {
      this.createMatter();
    },
  },

  mounted() {
    this.createMatter();
  },

  methods: {
    createMatter() {
      this.name =
        <string>this.$route.params.name || this.t("memories", "Albums");
    },

    back() {
      this.$router.push({ name: "albums" });
    },

    async downloadAlbum() {
      const res = await axios.post(
        API.ALBUM_DOWNLOAD(
          <string>this.$route.params.user,
          <string>this.$route.params.name
        )
      );
      if (res.status === 200 && res.data.handle) {
        downloadWithHandle(res.data.handle);
      }
    },

    /**
     * Change the sorting order
     * 1 = date, 2 = name
     */
    changeSort(order: 1 | 2) {
      this.config_albumListSort = order;
      this.updateSetting("albumListSort");
    },
  },
});
</script>
