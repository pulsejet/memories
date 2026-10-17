<template>
  <div ref="container" class="explore-outer hide-scrollbar-mobile">
    <div>
      <div class="title">{{ t('memories', 'Explore') }}</div>

      <Searchbar v-if="isNative" class="searchbar" />

      <ClusterHList
        v-if="recognize?.length"
        state-key="recognize"
        :title="t('memories', 'Recognize')"
        link="/recognize"
        :clusters="recognize"
        :circle="true"
      />
      <ClusterHList
        v-if="facerecognition?.length"
        state-key="facerecognition"
        :title="t('memories', 'Face Recognition')"
        link="/facerecognition"
        :clusters="facerecognition"
        :circle="true"
      />
      <ClusterHList
        v-if="places?.length"
        state-key="places"
        :title="t('memories', 'Places')"
        link="/places"
        :clusters="places"
      />
      <ClusterHList
        v-if="tags?.length"
        state-key="systemtags"
        :title="t('memories', 'Tags')"
        link="/tags"
        :clusters="tags"
      />

      <div class="link-list">
        <ExploreButton
          v-for="category of categories"
          :key="category.name"
          :to="category.link"
          :icon="category.icon"
          :label="category.name"
          @click="category.click?.()"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, reactive, onMounted, markRaw, watch } from 'vue';
import type { Component } from 'vue';

import Searchbar from '@components/header/Searchbar.vue';
import ClusterHList from '@components/ClusterHList.vue';
import ExploreButton from '@components/ExploreButton.vue';

import FolderIcon from 'vue-material-design-icons/Folder.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircle.vue';
import PanoramaIcon from 'vue-material-design-icons/PanoramaVariant.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import TrashIcon from 'vue-material-design-icons/Delete.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';

import { translate as t } from '@services/l10n';
import { config } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { useRouteState } from '@services/route-state';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as dav from '@services/dav';
import * as nativex from '@native';

import type { ICluster } from '@typings';

type Category = {
  name: string;
  icon: Component;
  link?: string;
  click?: () => void;
  if?: () => boolean;
};

const loading = ref(0);
const isNative = nativex.has();
const containerRef = useTemplateRef<HTMLDivElement>('container');
const abort = useAbort();

const recognize = ref<ICluster[] | null>(null);
const facerecognition = ref<ICluster[] | null>(null);
const places = ref<ICluster[] | null>(null);
const tags = ref<ICluster[] | null>(null);

useRouteState({
  state: { recognize, facerecognition, places, tags },
  scroll: { containerRef },
});

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
    name: t('memories', 'Trash'),
    icon: markRaw(TrashIcon),
    link: '/trash',
    if: () => windowDims.isMobile,
  },
  {
    name: t('memories', 'Settings'),
    icon: markRaw(CogIcon),
    link: undefined,
    click: _m.modals.showSettings,
    if: () => windowDims.isMobile,
  },
] as Category[]);

async function load<T>(fun: (signal: AbortSignal) => Promise<T>) {
  const signal = abort.signal;
  try {
    loading.value++;
    return await fun(signal);
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
  } finally {
    loading.value--;
  }
}

async function getRecognize(signal: AbortSignal) {
  recognize.value = await dav.getFaceList('recognize', { signal });
}

async function getFaceRecognition(signal: AbortSignal) {
  facerecognition.value = await dav.getFaceList('facerecognition', { signal });
}

async function getPlaces(signal: AbortSignal) {
  places.value = await dav.getPlaces({ signal });
}

async function getTags(signal: AbortSignal) {
  tags.value = (await dav.getTags({ signal })).sort((a, b) => b.count - a.count);
}

function maybeLoad() {
  if (config.recognize_enabled && !loaded.recognize) {
    loaded.recognize = true;
    if (recognize.value === null) {
      recognize.value = dav.getPlaceholderClusters(10);
    }
    load(getRecognize);
  }

  if (config.facerecognition_enabled && !loaded.facerecognition) {
    loaded.facerecognition = true;
    if (facerecognition.value === null) {
      facerecognition.value = dav.getPlaceholderClusters(10);
    }
    load(getFaceRecognition);
  }

  if (config.places_gis > 0 && !loaded.places) {
    loaded.places = true;
    if (places.value === null) {
      places.value = dav.getPlaceholderClusters(10);
    }
    load(getPlaces);
  }

  if (config.systemtags_enabled && !loaded.tags) {
    loaded.tags = true;
    if (tags.value === null) {
      tags.value = dav.getPlaceholderClusters(10);
    }
    load(getTags);
  }
}

onMounted(() => {
  maybeLoad();

  // Server copy may differ from cache; load newly enabled sections.
  watch(config, maybeLoad);

  // Remove categories that should not be shown
  categories.value = categories.value.filter((c) => !c.if || c.if());
});
</script>

<style lang="scss" scoped>
.explore-outer {
  position: relative;
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
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 8px;
    padding: 10px 24px 10px 4px;

    @media (max-width: 768px) {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      padding: 6px 7px;
      margin-bottom: 6px;
    }

    @media (max-width: 360px) {
      grid-template-columns: minmax(0, 1fr);
    }
  }
}
</style>
