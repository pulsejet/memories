<template>
  <div class="explore-outer hide-scrollbar">
    <ClusterHList v-if="recognize.length" :title="t('memories', 'Recognize')" link="/recognize" :clusters="recognize" />
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
        type="secondary"
      >
        <template #icon>
          <component :is="category.icon" />
        </template>
        <template>{{ category.name }}</template>
      </NcButton>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import config from '../services/static-config';
import axios from '@nextcloud/axios';

import ClusterHList from './ClusterHList.vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
import StarIcon from 'vue-material-design-icons/Star.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircle.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';

import type { ICluster, IConfig } from '../types';
import { API } from '../services/API';
import { translate as t } from '@nextcloud/l10n';

export default defineComponent({
  name: 'Explore',

  data: () => ({
    config: {} as IConfig,
    recognize: [] as ICluster[],
    facerecognition: [] as ICluster[],
    places: [] as ICluster[],
    tags: [] as ICluster[],

    categories: [
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
        click: globalThis.showSettings,
      },
    ] as {
      name: string;
      icon: any;
      link?: string;
      click?: () => void;
    }[],
  }),

  components: {
    ClusterHList,
    NcButton,
    StarIcon,
  },

  async mounted() {
    this.config = await config.getAll();

    if (this.config.recognize_enabled) {
      this.getRecognize();
    }

    if (this.config.facerecognition_enabled) {
      this.getFaceRecognition();
    }

    if (this.config.places_gis > 0) {
      this.getPlaces();
    }

    if (this.config.systemtags_enabled) {
      this.getTags();
    }
  },

  methods: {
    async getRecognize() {
      const res = await axios.get<ICluster[]>(API.FACE_LIST('recognize'));
      this.recognize = res.data.slice(0, 10);
    },

    async getFaceRecognition() {
      const res = await axios.get<ICluster[]>(API.FACE_LIST('facerecognition'));
      this.facerecognition = res.data.slice(0, 10);
    },

    async getPlaces() {
      const res = await axios.get<ICluster[]>(API.PLACE_LIST());
      this.places = res.data.slice(0, 10);
    },

    async getTags() {
      const res = await axios.get<ICluster[]>(API.TAG_LIST());
      this.tags = res.data.slice(0, 10);
    },
  },
});
</script>

<style lang="scss" scoped>
.explore-outer {
  height: 100%;
  overflow: auto;

  > .link-list {
    padding: 8px 10px;

    > .link {
      display: inline-block;
      width: calc(50% - 5px);
      margin: 0 5px 8px 0;
      border-radius: 10px;
    }
  }
}
</style>
