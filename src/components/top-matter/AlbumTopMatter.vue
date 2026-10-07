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
      <NcActions v-if="isAlbumList" :title="t('memories', 'Sorting order')" :forceMenu="true">
        <template #icon>
          <template v-if="isDateSort">
            <SortDateDIcon v-if="isDescending" :size="20" />
            <SortDateAIcon v-else :size="20" />
          </template>
          <template v-else-if="config.album_list_sort & constants.ALBUM_SORT_FLAGS.NAME">
            <SlotAlphabeticalDIcon v-if="isDescending" :size="20" />
            <SlotAlphabeticalAIcon v-else :size="20" />
          </template>
          <template v-else>
            <SortIcon :size="20" />
          </template>
        </template>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Last updated')"
          :model-value="sortField"
          value="last_update"
          @change="changeSort(constants.ALBUM_SORT_FLAGS.LAST_UPDATE)"
          close-after-click
        >
          {{ t('memories', 'Last updated') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Creation date')"
          :model-value="sortField"
          value="created"
          @change="changeSort(constants.ALBUM_SORT_FLAGS.CREATED)"
          close-after-click
        >
          {{ t('memories', 'Creation date') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Album name')"
          :model-value="sortField"
          value="name"
          @change="changeSort(constants.ALBUM_SORT_FLAGS.NAME)"
          close-after-click
        >
          {{ t('memories', 'Album name') }}
        </NcActionRadio>

        <NcActionSeparator />

        <NcActionRadio
          name="sort-dir"
          :aria-label="isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending')"
          :model-value="sortDir"
          value="asc"
          @change="setDescending(false)"
          close-after-click
        >
          {{ isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort-dir"
          :aria-label="isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending')"
          :model-value="sortDir"
          value="desc"
          @change="setDescending(true)"
          close-after-click
        >
          {{ isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending') }}
        </NcActionRadio>
      </NcActions>

      <NcActions :inline="windowDims.isMobile ? 1 : 3">
        <NcActionButton
          :aria-label="t('memories', 'Create new album')"
          :title="t('memories', 'Create new album')"
          @click="createModal?.open(false)"
          close-after-click
          v-if="isAlbumList"
        >
          {{ t('memories', 'Create new album') }}
          <template #icon> <PlusIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Share album')"
          :title="t('memories', 'Share album')"
          @click="openShareModal()"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Share album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Download album')"
          :title="t('memories', 'Download album')"
          @click="downloadAlbum()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t('memories', 'Download album') }}
          <template #icon> <DownloadIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Edit album details')"
          :title="t('memories', 'Edit album details')"
          @click="createModal?.open(true)"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Edit album details') }}
          <template #icon> <EditIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Remove album')"
          :title="t('memories', 'Remove album')"
          @click="deleteModal?.open()"
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

<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio';
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator';

import axios from '@nextcloud/axios';

import AlbumCreateModal from '@components/modal/AlbumCreateModal.vue';
import AlbumDeleteModal from '@components/modal/AlbumDeleteModal.vue';

import { downloadWithHandle } from '@services/dav';
import { API } from '@services/API';
import { windowDims } from '@services/common';
import { config, setConfig } from '@services/user-config';
import * as utils from '@services/utils/common';
import { constants } from '@services/constants';
import { t } from '@services/l10n';

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

defineOptions({
  name: 'AlbumTopMatter',
});

const route = useRoute();
const router = useRouter();

const createModal = useTemplateRef<InstanceType<typeof AlbumCreateModal>>('createModal');
const deleteModal = useTemplateRef<InstanceType<typeof AlbumDeleteModal>>('deleteModal');

const isAlbumList = computed((): boolean => {
  return !route.params.name?.toString();
});

const canEditAlbum = computed((): boolean => {
  return !isAlbumList.value && route.params.user?.toString() === utils.uid;
});

const name = computed((): string => {
  // Album name is displayed in the dynamic top matter (timeline)
  return isAlbumList.value ? t('memories', 'Albums') : String();
});

const isDateSort = computed((): boolean => {
  return (
    !!(config.album_list_sort & constants.ALBUM_SORT_FLAGS.CREATED) ||
    !!(config.album_list_sort & constants.ALBUM_SORT_FLAGS.LAST_UPDATE)
  );
});

const isDescending = computed((): boolean => {
  return !!(config.album_list_sort & constants.ALBUM_SORT_FLAGS.DESCENDING);
});

const sortField = computed((): string => {
  if (config.album_list_sort & constants.ALBUM_SORT_FLAGS.CREATED) return 'created';
  if (config.album_list_sort & constants.ALBUM_SORT_FLAGS.NAME) return 'name';
  return 'last_update';
});

const sortDir = computed((): string => {
  return isDescending.value ? 'desc' : 'asc';
});

function back() {
  router.go(-1);
}

function openShareModal() {
  _m.modals.albumShare(route.params.user?.toString(), route.params.name?.toString());
}

async function downloadAlbum() {
  const res = await axios.post(API.ALBUM_DOWNLOAD(route.params.user?.toString(), route.params.name?.toString()));
  if (res.status === 200 && res.data.handle) {
    downloadWithHandle(res.data.handle, route.params.name?.toString());
  }
}

/** Set sort choice */
function changeSort(flag: number) {
  const dir = config.album_list_sort & constants.ALBUM_SORT_FLAGS.DESCENDING;
  setConfig('album_list_sort', flag | dir);
}

/** Set sort direction */
function setDescending(val: boolean) {
  let sort = config.album_list_sort;
  if (val) {
    sort |= constants.ALBUM_SORT_FLAGS.DESCENDING;
  } else {
    sort &= ~constants.ALBUM_SORT_FLAGS.DESCENDING;
  }
  setConfig('album_list_sort', sort);
}
</script>
