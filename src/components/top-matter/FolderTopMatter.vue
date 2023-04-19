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
        <NcActionRouter :to="{ query: recursive ? {} : { recursive: '1' } }" close-after-click>
          {{ recursive ? t('memories', 'Folder View') : t('memories', 'Timeline View') }}
          <template #icon>
            <FoldersIcon v-if="recursive" :size="20" />
            <TimelineIcon v-else :size="20" />
          </template>
        </NcActionRouter>
        <NcActionButton :aria-label="t('memories', 'Share folder')" @click="share()" close-after-click>
          {{ t('memories', 'Share folder') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { TopMatterFolder, TopMatterType } from '../../types';

import UserConfig from '../../mixins/UserConfig';
const NcBreadcrumbs = () => import('@nextcloud/vue/dist/Components/NcBreadcrumbs');
const NcBreadcrumb = () => import('@nextcloud/vue/dist/Components/NcBreadcrumb');
import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';
import NcActionRouter from '@nextcloud/vue/dist/Components/NcActionRouter';

import * as utils from '../../services/Utils';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';

export default defineComponent({
  name: 'FolderTopMatter',
  components: {
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    NcActionRouter,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    topMatter: null as TopMatterFolder | null,
    recursive: false,
  }),

  watch: {
    $route: function (from: any, to: any) {
      this.createMatter();
    },
  },

  mounted() {
    this.createMatter();
  },

  methods: {
    createMatter() {
      if (this.$route.name === 'folders') {
        let path: any = this.$route.params.path || '';
        if (typeof path === 'string') {
          path = path.split('/');
        }

        this.topMatter = {
          type: TopMatterType.FOLDER,
          list: path
            .filter((x) => x)
            .map((x, idx, arr) => {
              return {
                text: x,
                path: arr.slice(0, idx + 1).join('/'),
              };
            }),
        };
        this.recursive = this.$route.query.recursive === '1';
      } else {
        this.topMatter = null;
        this.recursive = false;
      }
    },

    share() {
      globalThis.shareNodeLink(utils.getFolderRoutePath(this.config_foldersPath));
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  .breadcrumb {
    min-width: 0;
  }
}
</style>
