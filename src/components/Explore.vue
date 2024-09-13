<template>
  <div class="explore-outer hide-scrollbar-mobile">
    <XLoadingIcon v-if="loading" class="fill-block" />

    <div v-else>
      <div class="title">{{ t('memories', 'Explore') }}</div>

      <Searchbar v-if="isNative" class="searchbar" />

      <ClusterHList
        v-if="recognize.length"
        :title="t('memories', 'Recognize')"
        link="/recognize"
        :clusters="recognize"
      />
      <ClusterHList
        v-if="facerecognition.length"
        :title="t('memories', 'Face Recognition')"
        link="/facerecognition"
        :clusters="facerecognition"
      />
      <ClusterHList v-if="places.length" :title="t('memories', 'Places')" link="/places" :clusters="places" />
      <ClusterHList v-if="tags.length" :title="t('memories', 'Tags')" link="/tags" :clusters="tags" />

      <div class="link-list">
        <NcButton
          class="link"
          v-for="category of categories"
          :ariaLabel="category.name"
          :key="category.name"
          :to="category.link"
          @click="category.click?.()"
          type="tertiary-no-background"
        >
          <template #icon>
            <component :is="category.icon" />
          </template>
          <template>{{ category.name }}</template>
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Component } from 'vue';

import Searchbar from '@components/header/Searchbar.vue';
import ClusterHList from '@components/ClusterHList.vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import FolderIcon from 'vue-material-design-icons/Folder.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircle.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';

import { translate as t } from '@services/l10n';
import config from '@services/static-config';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import type { ICluster, IConfig } from '@typings';

export default defineComponent({
  name: 'Explore',

  components: {
    Searchbar,
    ClusterHList,
    NcButton,
    StarIcon,
  },

  data: () => ({
    loading: 0,
    isNative: nativex.has(),

    config: {} as IConfig,
    recognize: [] as ICluster[],
    facerecognition: [] as ICluster[],
    places: [] as ICluster[],
    tags: [] as ICluster[],

    categories: [
      {
        name: t('memories', 'Folders'),
        icon: FolderIcon,
        link: '/folders',
      },
      {
        name: t('memories', 'Favorites'),
        icon: StarIcon,
        link: '/favorites',
      },
      {
        name: t('memories', 'Videos'),
        icon: VideoIcon,
        link: '/videos',
      },
      {
        name: t('memories', 'Archive'),
        icon: ArchiveIcon,
        link: '/archive',
      },
      {
        name: t('memories', 'On this day'),
        icon: CalendarIcon,
        link: '/thisday',
      },
      {
        name: t('memories', 'Map'),
        icon: MapIcon,
        link: '/map',
      },
      {
        name: t('memories', 'Settings'),
        icon: CogIcon,
        link: undefined,
        click: _m.modals.showSettings,
        if: () => utils.isMobile(),
      },
    ] as {
      name: string;
      icon: Component;
      link?: string;
      click?: () => void;
      if?: () => boolean;
    }[],
  }),

  async mounted() {
    const res: IConfig | undefined = await this.load(config.getAll.bind(config));
    if (!res) return;
    this.config = res;

    if (this.config.recognize_enabled) {
      this.load(this.getRecognize);
    }

    if (this.config.facerecognition_enabled) {
      this.load(this.getFaceRecognition);
    }

    if (this.config.places_gis > 0) {
      this.load(this.getPlaces);
    }

    if (this.config.systemtags_enabled) {
      this.load(this.getTags);
    }

    // Remove categories that should not be shown
    this.categories = this.categories.filter((c) => !c.if || c.if());
  },

  methods: {
    async load<T>(fun: () => Promise<T>) {
      try {
        this.loading++;
        return await fun();
      } catch (e) {
        console.error(e);
      } finally {
        this.loading--;
      }
    },

    async getRecognize() {
      this.recognize = (await dav.getFaceList('recognize')).slice(0, 10);
    },

    async getFaceRecognition() {
      this.facerecognition = (await dav.getFaceList('facerecognition')).slice(0, 10);
    },

    async getPlaces() {
      this.places = (await dav.getPlaces()).slice(0, 10);
    },

    async getTags() {
      this.tags = (await dav.getTags()).sort((a, b) => b.count - a.count).slice(0, 10);
    },
  },
});
</script>

<style lang="scss" scoped>
.explore-outer {
  height: 100%;
  overflow-y: auto;
  overflow-x: hidden;
  padding-top: 8px;

  .title {
    margin: 0 14px;
    padding-bottom: 2px;
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    border-bottom: 1px solid var(--color-border-dark);

    @media (max-width: 768px) {
      display: none;
    }
  }

  .searchbar {
    margin-top: 5px;
    margin-bottom: 10px;
  }

  .cluster-hlist {
    margin-top: 20px;
    width: calc(100% - 24px);

    @media (max-width: 768px) {
      margin-top: 0;
      width: 100%;
    }
  }

  .link-list {
    padding: 10px 4px;
    line-height: 0;

    @media (max-width: 768px) {
      padding: 6px 7px;
      margin-bottom: 6px;
    }

    > .link {
      line-height: initial;
      display: inline-block;
      margin: 3px;
      opacity: 0.8;
      @media (max-width: 768px) {
        width: calc(50% - 6px);
        border-radius: 10px;
        opacity: 1;
      }
    }
  }
}
</style>
