<template>
  <div class="top-matter">
    <NcBreadcrumbs :key="$route.path">
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
          :disabled="uploadHandler()?.processing"
          @click="uploadHandler()?.startUpload()"
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

        <NcActionButton
          :aria-label="t('memories', 'Go to date')"
          @click="openDatePicker()"
          close-after-click
        >
          {{ t('memories', 'Go to date') }}
          <template #icon> <CalendarSearchIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>

      <input
        ref="dateInput"
        type="date"
        class="date-input-hidden"
        @change="onDateSelected"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs.js');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb.js');
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';

import * as utils from '@services/utils';
import * as nativex from '@native';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';
import CalendarSearchIcon from 'vue-material-design-icons/CalendarSearch.vue';

export default defineComponent({
  name: 'FolderTopMatter',

  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    PublicUploadHandler,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
    UploadIcon,
    CalendarSearchIcon,
  },

  mixins: [UserConfig],

  computed: {
    list(): {
      text: string;
      path: string[];
      idx: number;
    }[] {
      let path: string[] | string = this.$route.params.path || '';
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

    recursive(): boolean {
      return !!this.$route.query.recursive;
    },

    rootFolderName(): string {
      return this.routeIsPublic ? this.initstate.shareTitle : this.t('memories', 'Home');
    },

    isNative(): boolean {
      return nativex.has();
    },

    allowPublicUpload(): boolean {
      return this.routeIsPublic && this.initstate.allow_upload === true;
    },
  },

  methods: {
    share(): void {
      _m.modals.shareNodeLink(utils.getFolderRoutePath(this.config.folders_path));
    },

    upload(): void {
      _m.modals.upload();
    },

    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

    getRoute(path: string[]): object {
      return {
        ...this.$route,
        params: { path },
        hash: undefined,
      };
    },

    uploadHandler(): InstanceType<typeof PublicUploadHandler> | null {
      return (this.$refs.uploadHandler as InstanceType<typeof PublicUploadHandler>) || null;
    },

    openDatePicker() {
      const input = this.$refs.dateInput as HTMLInputElement;
      const event = { result: null as { min: Date; max: Date } | null };
      utils.bus.emit('memories:timeline:getDateRange', event);
      if (event.result) {
        input.min = event.result.min.toISOString().split('T')[0];
        input.max = event.result.max.toISOString().split('T')[0];
      }

      // Temporarily make visible for showPicker to work
      input.style.width = '1px';
      input.style.height = '1px';
      try {
        input.showPicker();
      } catch {
        input.click();
      }
      input.style.width = '';
      input.style.height = '';
    },

    onDateSelected(event: Event) {
      const input = event.target as HTMLInputElement;
      if (!input.value) return;
      const date = new Date(input.value + 'T00:00:00Z');
      utils.bus.emit('memories:timeline:scrollToDate', date);
      input.value = '';
    },
  },
});
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

  .date-input-hidden {
    position: absolute;
    width: 0;
    height: 0;
    overflow: hidden;
    border: 0;
    padding: 0;
    margin: 0;
  }
}
</style>
