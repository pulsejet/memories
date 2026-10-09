<template>
  <div ref="container" class="explore-outer hide-scrollbar-mobile">
    <XLoadingIcon v-if="loading" class="fill-block top-left" />

    <div>
      <div class="title">{{ t('memories', 'Explore') }}</div>

      <Searchbar v-if="isNative" class="searchbar" />

      <ClusterHList
        v-if="recognize.length"
        state-key="recognize"
        :title="t('memories', 'Recognize')"
        link="/recognize"
        :clusters="recognize"
      />
      <ClusterHList
        v-if="facerecognition.length"
        state-key="facerecognition"
        :title="t('memories', 'Face Recognition')"
        link="/facerecognition"
        :clusters="facerecognition"
      />
      <ClusterHList
        v-if="places.length"
        state-key="places"
        :title="t('memories', 'Places')"
        link="/places"
        :clusters="places"
      />
      <ClusterHList
        v-if="tags.length"
        state-key="systemtags"
        :title="t('memories', 'Tags')"
        link="/tags"
        :clusters="tags"
      />

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
import { ref, useTemplateRef, reactive, onMounted, markRaw, watch } from 'vue';
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
import { config } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { useRouteState } from '@services/route-state';
import { isAbortError, useAbort } from '@services/utils/abort';
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

const recognize = ref([] as ICluster[]);
const facerecognition = ref([] as ICluster[]);
const places = ref([] as ICluster[]);
const tags = ref([] as ICluster[]);

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
  recognize.value = (await dav.getFaceList('recognize', { signal })).slice(0, 10);
}

async function getFaceRecognition(signal: AbortSignal) {
  facerecognition.value = (await dav.getFaceList('facerecognition', { signal })).slice(0, 10);
}

async function getPlaces(signal: AbortSignal) {
  places.value = (await dav.getPlaces({ signal })).slice(0, 10);
}

async function getTags(signal: AbortSignal) {
  tags.value = (await dav.getTags({ signal })).sort((a, b) => b.count - a.count).slice(0, 10);
}

function maybeLoad() {
  if (config.recognize_enabled && !loaded.recognize) {
    loaded.recognize = true;
    load(getRecognize);
  }

  if (config.facerecognition_enabled && !loaded.facerecognition) {
    loaded.facerecognition = true;
    load(getFaceRecognition);
  }

  if (config.places_gis > 0 && !loaded.places) {
    loaded.places = true;
    load(getPlaces);
  }

  if (config.systemtags_enabled && !loaded.tags) {
    loaded.tags = true;
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
