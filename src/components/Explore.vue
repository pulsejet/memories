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
          variant="tertiary-no-background"
        >
          <template #icon>
            <component :is="category.icon" />
          </template>
          {{ category.name }}
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, markRaw } from 'vue';
import type { Component } from 'vue';

import Searchbar from '@components/header/Searchbar.vue';
import ClusterHList from '@components/ClusterHList.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import NcButton from '@nextcloud/vue/components/NcButton';

import FolderIcon from 'vue-material-design-icons/Folder.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircle.vue';
import PanoramaIcon from 'vue-material-design-icons/PanoramaVariant.vue';
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

type Category = {
  name: string;
  icon: Component;
  link?: string;
  click?: () => void;
  if?: () => boolean;
};

const loading = ref(0);
const isNative = nativex.has();

const localConfig = ref({} as IConfig);
const recognize = ref([] as ICluster[]);
const facerecognition = ref([] as ICluster[]);
const places = ref([] as ICluster[]);
const tags = ref([] as ICluster[]);
const loaded = reactive({
  recognize: false,
  facerecognition: false,
  places: false,
  tags: false,
});

const categories = ref([
  {
    name: t('memories', 'Folders'),
    icon: markRaw(FolderIcon),
    link: '/folders',
  },
  {
    name: t('memories', 'Favorites'),
    icon: markRaw(StarIcon),
    link: '/favorites',
  },
  {
    name: t('memories', 'Videos'),
    icon: markRaw(VideoIcon),
    link: '/videos',
  },
  {
    name: t('memories', 'Panoramas'),
    icon: markRaw(PanoramaIcon),
    link: '/panoramas',
  },
  {
    name: t('memories', 'Archive'),
    icon: markRaw(ArchiveIcon),
    link: '/archive',
  },
  {
    name: t('memories', 'On this day'),
    icon: markRaw(CalendarIcon),
    link: '/thisday',
  },
  {
    name: t('memories', 'Map'),
    icon: markRaw(MapIcon),
    link: '/map',
  },
  {
    name: t('memories', 'Settings'),
    icon: markRaw(CogIcon),
    link: undefined,
    click: _m.modals.showSettings,
    if: () => _m.window.isMobile,
  },
] as Category[]);

async function load<T>(fun: () => Promise<T>) {
  try {
    loading.value++;
    return await fun();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value--;
  }
}

async function getRecognize() {
  recognize.value = (await dav.getFaceList('recognize')).slice(0, 10);
}

async function getFaceRecognition() {
  facerecognition.value = (await dav.getFaceList('facerecognition')).slice(0, 10);
}

async function getPlaces() {
  places.value = (await dav.getPlaces()).slice(0, 10);
}

async function getTags() {
  tags.value = (await dav.getTags()).sort((a, b) => b.count - a.count).slice(0, 10);
}

function maybeLoad() {
  if (localConfig.value.recognize_enabled && !loaded.recognize) {
    loaded.recognize = true;
    load(getRecognize);
  }

  if (localConfig.value.facerecognition_enabled && !loaded.facerecognition) {
    loaded.facerecognition = true;
    load(getFaceRecognition);
  }

  if (localConfig.value.places_gis > 0 && !loaded.places) {
    loaded.places = true;
    load(getPlaces);
  }

  if (localConfig.value.systemtags_enabled && !loaded.tags) {
    loaded.tags = true;
    load(getTags);
  }
}

function onConfigChanged() {
  localConfig.value = { ...config.getDefault() };
  maybeLoad();
}

onMounted(async () => {
  const res: IConfig | undefined = await load(config.getAll.bind(config));
  if (!res) return;
  localConfig.value = res;
  maybeLoad();

  // Server copy may differ from cache; load newly enabled sections.
  utils.bus.on('memories:user-config-changed', onConfigChanged);

  // Remove categories that should not be shown
  categories.value = categories.value.filter((c) => !c.if || c.if());
});

onBeforeUnmount(() => {
  utils.bus.off('memories:user-config-changed', onConfigChanged);
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
