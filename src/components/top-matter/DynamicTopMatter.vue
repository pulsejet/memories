<template>
  <div class="dtm-container" v-if="currentmatter || viewName">
    <div v-if="viewName" class="header">{{ viewName }}</div>
    <component ref="child" v-if="currentmatter" :is="currentmatter" @load="$emit('load')" />
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw, nextTick, useTemplateRef, type Component } from 'vue';
import { useRoute } from 'vue-router';

import { config } from '@services/user-config';
import {
  useRouteIsAlbums,
  useRouteIsBase,
  useRouteIsFolderShare,
  useRouteIsFolders,
  useRouteIsPeople,
  useRouteIsPlaces,
  useRouteIsPublic,
  useRouteIsTags,
} from '@services/route-checker';
import { initstate } from '@services/utils';

import AlbumDynamicTopMatter from './AlbumDynamicTopMatter.vue';
import FolderDynamicTopMatter from './FolderDynamicTopMatter.vue';
import PlacesDynamicTopMatterVue from './PlacesDynamicTopMatter.vue';
import OnThisDay from './OnThisDay.vue';
import * as strings from '@services/strings';

// Auto-hide top header on public shares if redundant
import './PublicShareHeader';

defineOptions({
  name: 'DynamicTopMatter',
});

defineEmits<{
  load: [];
}>();

const route = useRoute();
const routeIsFolders = useRouteIsFolders();
const routeIsFolderShare = useRouteIsFolderShare();
const routeIsPlaces = useRouteIsPlaces();
const routeIsAlbums = useRouteIsAlbums();
const routeIsBase = useRouteIsBase();
const routeIsPublic = useRouteIsPublic();
const routeIsTags = useRouteIsTags();
const routeIsPeople = useRouteIsPeople();

const child = useTemplateRef<{ refresh?(): Promise<boolean> }>('child');

const currentmatter = computed((): Component | null => {
  if (routeIsFolders.value || (routeIsFolderShare.value && initstate.shareType === 'folder')) {
    return markRaw(FolderDynamicTopMatter);
  } else if (routeIsPlaces.value) {
    return markRaw(PlacesDynamicTopMatterVue);
  } else if (routeIsAlbums.value) {
    return markRaw(AlbumDynamicTopMatter);
  } else if (routeIsBase.value && config.enable_top_memories) {
    return markRaw(OnThisDay);
  }

  return null;
});

/** Get view name for dynamic top matter */
const viewName = computed((): string => {
  // Show album name for album view
  if (routeIsAlbums.value) {
    return strings.albumDisplayName(route.params.name?.toString() ?? String());
  }

  // Show share name for public shares, except for folder share,
  // because the name is already present in the breadcrumbs
  if (routeIsPublic.value && !routeIsFolderShare.value) {
    return initstate.shareTitle;
  }

  // Only static top matter for these routes
  if (routeIsTags.value || routeIsPeople.value || routeIsPlaces.value) {
    return String();
  }

  return strings.viewName(route.name?.toString() ?? '');
});

async function refresh(): Promise<boolean> {
  if (currentmatter.value) {
    await nextTick();
    return (await child.value?.refresh?.()) ?? false;
  }

  return false;
}

defineExpose({ refresh });
</script>

<style lang="scss" scoped>
.dtm-container {
  > .header {
    font-size: 2.5em;
    position: relative;
    display: block;
    line-height: 1.2em;

    // more padding on right for scroller thumb
    padding: 25px 60px 10px 10px;

    @media (max-width: 768px) {
      font-size: 1.8em;
      padding: 15px 30px 7px 12px;
      html.native & {
        // header is empty, more top padding
        padding: 25px 30px 7px 18px;
      }
    }
  }
}
</style>
