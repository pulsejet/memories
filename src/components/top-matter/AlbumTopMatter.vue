<template>
  <div class="top-matter">
    <NcActions v-if="!isAlbumList">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name }}</div>

    <div class="right-actions">
      <!-- Search Bar-->
      <div v-if="isAlbumList" class="search-bar-container">
        <NcInputField
          :value="searchQuery"
          :placeholder="t('memories', 'Search')"
          @input="searchQuery = $event.target.value"
          @keyup.enter="sendSearchQuery(searchQuery)"
        />
      </div>

      <NcActions :forceMenu="true" v-if="isAlbumList">
        <template #icon>
          <SortIcon :size="20" />
        </template>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Sort by date')"
          :checked="config.album_list_sort === 1"
          @change="changeSort(1)"
          close-after-click
        >
          {{ t('memories', 'Sort by date') }}
          <template #icon> <SortDateIcon :size="20" /> </template>
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Sort by name')"
          :checked="config.album_list_sort === 2"
          @change="changeSort(2)"
          close-after-click
        >
          {{ t('memories', 'Sort by name') }}
          <template #icon> <SlotAlphabeticalIcon :size="20" /> </template>
        </NcActionRadio>
      </NcActions>

      <NcActions :inline="isMobile ? 1 : 3">
        <NcActionButton
          :aria-label="t('memories', 'Create new album')"
          @click="refs.createModal.open(false)"
          close-after-click
          v-if="isAlbumList"
        >
          {{ t('memories', 'Create new album') }}
          <template #icon> <PlusIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Share album')"
          @click="openShareModal()"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Share album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Download album')"
          @click="downloadAlbum()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t('memories', 'Download album') }}
          <template #icon> <DownloadIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Edit album details')"
          @click="refs.createModal.open(true)"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Edit album details') }}
          <template #icon> <EditIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Remove album')"
          @click="refs.deleteModal.open()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t('memories', 'Remove album') }}
          <template #icon> <DeleteIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <AlbumCreateModal ref="createModal" />
    <AlbumDeleteModal ref="deleteModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox';
import NcActionRadio from '@nextcloud/vue/dist/Components/NcActionRadio';
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField';

import axios from '@nextcloud/axios';

import AlbumCreateModal from '@components/modal/AlbumCreateModal.vue';
import AlbumDeleteModal from '@components/modal/AlbumDeleteModal.vue';

import { downloadWithHandle } from '@services/dav/download';
import { API } from '@services/API';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import SortIcon from 'vue-material-design-icons/SortVariant.vue';
import SlotAlphabeticalIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SortDateIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

export default defineComponent({
  name: 'AlbumTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionRadio,
    NcInputField,

    AlbumCreateModal,
    AlbumDeleteModal,

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

  created() {
    // Reset the search query when the component is created
    this.resetSearchQuery();
  },

  computed: {
    refs() {
      return this.$refs as {
        createModal: InstanceType<typeof AlbumCreateModal>;
        deleteModal: InstanceType<typeof AlbumDeleteModal>;
      };
    },

    isAlbumList(): boolean {
      return !this.$route.params.name;
    },

    canEditAlbum(): boolean {
      return !this.isAlbumList && this.$route.params.user === utils.uid;
    },

    name(): string {
      // Album name is displayed in the dynamic top matter (timeline)
      return this.isAlbumList ? this.t('memories', 'Albums') : String();
    },

    isMobile(): boolean {
      return utils.isMobile();
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    openShareModal() {
      _m.modals.albumShare(this.$route.params.user, this.$route.params.name);
    },

    async downloadAlbum() {
      const res = await axios.post(API.ALBUM_DOWNLOAD(this.$route.params.user, this.$route.params.name));
      if (res.status === 200 && res.data.handle) {
        downloadWithHandle(res.data.handle);
      }
    },

    resetSearchQuery() {
      // Reset the search query to an empty string or default state
      this.config.album_list_search = '';
      // Update the setting to ensure consistency
      this.updateSetting('album_list_search');
    },

    sendSearchQuery(query: string) {
      this.config.album_list_search = query;
      this.updateSetting('album_list_search');
    },

    /**
     * Change the sorting order
     * 1 = date, 2 = name
     */
    changeSort(order: 1 | 2) {
      this.config.album_list_sort = order;
      this.updateSetting('album_list_sort');
    },
  },
});
</script>

<style lang="scss" scoped>
.search-bar-container {
  // Such that the search bar is placed on the left side of the sort menu
  display: inline-block;
}
</style>
