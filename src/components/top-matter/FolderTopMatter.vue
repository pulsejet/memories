<template>
  <div class="top-matter">
    <NcBreadcrumbs :key="route.path">
      <NcBreadcrumb :name="rootFolderName" :to="getRoute([])" :force-icon-text="routeIs.Public">
        <template #icon>
          <ShareIcon v-if="routeIs.Public" :size="20" />
          <HomeIcon v-else :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="folder in list" :key="folder.idx" :name="folder.text" :to="getRoute(folder.path)" />
    </NcBreadcrumbs>

    <div class="right-actions">
      <!-- Progress bar for upload -->
      <PublicUploadHandler ref="uploadHandler" v-if="allowPublicUpload" />

      <!-- Filters for the recursive (timeline) folder view -->
      <template v-if="showFilters">
        <NcSelect
          v-if="config.systemtags_enabled"
          class="tag-filter"
          :model-value="selectedTag"
          :options="tagOptions"
          :input-label="t('memories', 'Filter by tag')"
          :label-outside="true"
          :placeholder="t('memories', 'Filter by tag')"
          :loading="tagsLoading"
          @update:model-value="setTag"
        />

        <NcActions :force-menu="true" :aria-label="t('memories', 'Media type')">
          <template #icon>
            <FilterIcon v-if="mediaType" :size="20" />
            <FilterOutlineIcon v-else :size="20" />
          </template>

          <NcActionRadio
            v-for="opt in mediaTypes"
            :key="opt.value"
            name="media-type"
            :model-value="mediaType"
            :value="opt.value"
            @change="setMediaType(opt.value)"
            close-after-click
          >
            {{ opt.label }}
          </NcActionRadio>
        </NcActions>
      </template>

      <NcActions :inline="3">
        <NcActionButton
          v-if="!routeIs.Public"
          :aria-label="t('memories', 'Share folder')"
          @click="share()"
          close-after-click
        >
          {{ t('memories', 'Share folder') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton
          v-if="!routeIs.Public"
          :aria-label="t('memories', 'Upload files')"
          @click="upload()"
          close-after-click
        >
          {{ t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <!-- Public upload button -->
        <NcActionButton
          v-if="allowPublicUpload"
          :aria-label="t('memories', 'Upload files')"
          :disabled="uploadHandler?.processing"
          @click="uploadHandler?.startUpload()"
        >
          {{ t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton @click="toggleRecursive" close-after-click>
          {{ recursive ? t('memories', 'Folder view') : t('memories', 'Timeline view') }}
          <template #icon>
            <FoldersIcon v-if="recursive" :size="20" />
            <TimelineIcon v-else :size="20" />
          </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs';
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb';
import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio';
import NcSelect from '@nextcloud/vue/components/NcSelect';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';

import { t } from '@services/l10n';
import { useRouteIs } from '@services/route-is';
import { config } from '@services/user-config';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';
import { isAbortError } from '@services/utils/abort';
import initstate from '@services/init-state';

import type { ICluster } from '@typings';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';
import FilterIcon from 'vue-material-design-icons/Filter.vue';
import FilterOutlineIcon from 'vue-material-design-icons/FilterOutline.vue';

defineOptions({
  name: 'FolderTopMatter',
});

const route = useRoute();
const router = useRouter();
const routeIs = useRouteIs();
const uploadHandler = useTemplateRef<InstanceType<typeof PublicUploadHandler>>('uploadHandler');

const list = computed(
  (): {
    text: string;
    path: string[];
    idx: number;
  }[] => {
    let path: string[] | string = route.params.path || '';
    if (typeof path === 'string') {
      path = path.split('/');
    }

    return path
      .filter(Boolean) // non-empty
      .map((text, idx, arr) => {
        const path = arr.slice(0, idx + 1);
        return { text, path, idx };
      });
  },
);

const recursive = computed((): boolean => {
  return !!route.query.recursive;
});

const rootFolderName = computed((): string => {
  return routeIs.Public ? initstate.shareTitle : t('memories', 'Home');
});

const allowPublicUpload = computed((): boolean => {
  return routeIs.Public && initstate.allow_upload === true;
});

function share(): void {
  _m.modals.shareNodeLink(utils.getFolderRoutePath(config.folders_path, { route }));
}

function upload(): void {
  _m.modals.upload();
}

function toggleRecursive(): void {
  router.replace({
    query: {
      ...router.currentRoute.value.query,
      recursive: recursive.value ? undefined : String(1),
      // Filters only apply to the recursive view
      tag: undefined,
      type: undefined,
    },
  });
}

/** Filters are only available in the recursive view of a user's own folders */
const showFilters = computed((): boolean => recursive.value && !routeIs.Public);

const mediaTypes = [
  { value: '', label: t('memories', 'All media') },
  { value: 'photo', label: t('memories', 'Photos only') },
  { value: 'video', label: t('memories', 'Videos only') },
];

const mediaType = computed((): string => route.query.type?.toString() ?? '');

function setMediaType(type: string): void {
  router.replace({
    query: {
      ...router.currentRoute.value.query,
      type: type || undefined,
    },
  });
}

/** Tags used in the current folder (recursively) */
const tags = ref<ICluster[]>([]);
const tagsLoading = ref(false);
let tagsAbort: AbortController | null = null;

type TagOption = { name: string; label: string };

const tagOptions = computed((): TagOption[] =>
  tags.value.map((tag) => ({ name: tag.name, label: `${tag.display_name ?? tag.name} (${tag.count})` })),
);

const selectedTag = computed((): TagOption | null => {
  const name = route.query.tag?.toString();
  if (!name) return null;
  return tagOptions.value.find((opt) => opt.name === name) ?? { name, label: name };
});

function setTag(tag: TagOption | null): void {
  router.replace({
    query: {
      ...router.currentRoute.value.query,
      tag: tag?.name || undefined,
    },
  });
}

async function loadTags(): Promise<void> {
  tagsAbort?.abort();
  if (!showFilters.value || !config.systemtags_enabled) {
    tags.value = [];
    return;
  }

  const abort = (tagsAbort = new AbortController());
  tagsLoading.value = true;
  try {
    tags.value = await dav.getTags({ folder: folderPath.value, signal: abort.signal });
  } catch (e) {
    if (!isAbortError(e)) {
      console.error('Failed to load tags', e);
      tags.value = [];
    }
  } finally {
    if (tagsAbort === abort) tagsLoading.value = false;
  }
}

const folderPath = computed((): string => utils.getFolderRoutePath(config.folders_path, { route }));
watch([folderPath, showFilters], loadTags, { immediate: true });

function getRoute(path: string[]): object {
  return {
    name: route.name,
    params: { ...route.params, path },
    query: route.query,
  };
}
</script>

<style lang="scss" scoped>
.top-matter {
  .breadcrumb {
    min-width: 0;
    height: unset;
    .share-name {
      margin-left: 0.75em;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px; // Add spacing between actions and progress bar

    .tag-filter {
      min-width: 200px;
      margin: 0;
    }
  }
}
</style>
