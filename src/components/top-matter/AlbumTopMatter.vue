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
      <!-- Sorting options -->
      <template v-if="isAlbumList">
        <NcActions :forceMenu="true">
          <template #icon>
            <SortIcon :size="20" />
          </template>

          <NcActionRadio
            name="sort"
            :aria-label="t('memories', 'Creation date')"
            :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.CREATED)"
            @change="changeSort(c.ALBUM_SORT_FLAGS.CREATED)"
            close-after-click
          >
            {{ t('memories', 'Creation date') }}
          </NcActionRadio>

          <NcActionRadio
            name="sort"
            :aria-label="t('memories', 'Album name')"
            :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.NAME)"
            @change="changeSort(c.ALBUM_SORT_FLAGS.NAME)"
            close-after-click
          >
            {{ t('memories', 'Album name') }}
          </NcActionRadio>
        </NcActions>

        <NcActions :forceMenu="true">
          <template #icon>
            <template v-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.CREATED">
              <SortDateDIcon v-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.DESCENDING" :size="20" />
              <SortDateAIcon v-else :size="20" />
            </template>
            <template v-else-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.NAME">
              <SlotAlphabeticalDIcon v-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.DESCENDING" :size="20" />
              <SlotAlphabeticalAIcon v-else :size="20" />
            </template>
            <template v-else>
              <SortIcon :size="20" />
            </template>
          </template>

          <NcActionRadio
            name="sort-dir"
            :aria-label="t('memories', 'Ascending')"
            :checked="!(config.album_list_sort & c.ALBUM_SORT_FLAGS.DESCENDING)"
            @change="setDescending(false)"
            close-after-click
          >
            {{ t('memories', 'Ascending') }}
          </NcActionRadio>

          <NcActionRadio
            name="sort-dir"
            :aria-label="t('memories', 'Descending')"
            :checked="!!(config.album_list_sort & c.ALBUM_SORT_FLAGS.DESCENDING)"
            @change="setDescending(true)"
            close-after-click
          >
            {{ t('memories', 'Descending') }}
          </NcActionRadio>
        </NcActions>
      </template>

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
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';
import NcActionRadio from '@nextcloud/vue/dist/Components/NcActionRadio.js';

import axios from '@nextcloud/axios';

import AlbumCreateModal from '@components/modal/AlbumCreateModal.vue';
import AlbumDeleteModal from '@components/modal/AlbumDeleteModal.vue';

import { downloadWithHandle } from '@services/dav';
import { API } from '@services/API';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import SortIcon from 'vue-material-design-icons/SortVariant.vue';
import SlotAlphabeticalAIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SlotAlphabeticalDIcon from 'vue-material-design-icons/SortAlphabeticalDescending.vue';
import SortDateAIcon from 'vue-material-design-icons/SortCalendarAscending.vue';
import SortDateDIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

export default defineComponent({
  name: 'AlbumTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionRadio,

    AlbumCreateModal,
    AlbumDeleteModal,

    BackIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
    SortIcon,
    SlotAlphabeticalAIcon,
    SlotAlphabeticalDIcon,
    SortDateAIcon,
    SortDateDIcon,
  },

  mixins: [UserConfig],

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

    /** Set sort choice */
    changeSort(flag: number) {
      const dir = this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.DESCENDING;
      this.config.album_list_sort = flag | dir;
      this.updateSetting('album_list_sort');
    },

    /** Set sort direction */
    setDescending(val: boolean) {
      if (val) {
        this.config.album_list_sort |= this.c.ALBUM_SORT_FLAGS.DESCENDING;
      } else {
        this.config.album_list_sort &= ~this.c.ALBUM_SORT_FLAGS.DESCENDING;
      }
      this.updateSetting('album_list_sort');
    },
  },
});
</script>
