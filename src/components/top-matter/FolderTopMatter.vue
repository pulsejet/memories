<template>
  <div class="top-matter">
    <NcBreadcrumbs :key="route.path">
      <NcBreadcrumb :name="rootFolderName" :to="getRoute([])" :force-icon-text="routeIsPublic">
        <template #icon>
          <ShareIcon v-if="routeIsPublic" :size="20" />
          <HomeIcon v-else :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="folder in list" :key="folder.idx" :name="folder.text" :to="getRoute(folder.path)" />
    </NcBreadcrumbs>

    <div class="right-actions">
      <!-- Progress bar for upload -->
      <PublicUploadHandler ref="uploadHandler" v-if="allowPublicUpload" />

      <NcActions :inline="3">
        <NcActionButton
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Share folder')"
          @click="share()"
          close-after-click
        >
          {{ t('memories', 'Share folder') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton
          v-if="!routeIsPublic"
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
import { computed, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs';
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb';
import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';

import { t } from '@services/l10n';
import { useRouteIsPublic } from '@services/route-checker';
import { useUserConfig } from '@services/user-config';
import * as utils from '@services/utils';
import { initstate } from '@services/utils';
import * as nativex from '@native';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';

defineOptions({
  name: 'FolderTopMatter',
});

const route = useRoute();
const router = useRouter();
const routeIsPublic = useRouteIsPublic();
const { config } = useUserConfig();

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
  return routeIsPublic.value ? initstate.shareTitle : t('memories', 'Home');
});

const allowPublicUpload = computed((): boolean => {
  return routeIsPublic.value && initstate.allow_upload === true;
});

function share(): void {
  _m.modals.shareNodeLink(utils.getFolderRoutePath(config.folders_path));
}

function upload(): void {
  _m.modals.upload();
}

function toggleRecursive(): void {
  router.replace({
    query: {
      ...router.currentRoute.value.query,
      recursive: recursive.value ? undefined : String(1),
    },
  });
}

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
  }
}
</style>
