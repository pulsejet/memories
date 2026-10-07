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
import { routeIs } from '@services/router';
import initstate from '@services/init-state';

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
const child = useTemplateRef<{ refresh?(): Promise<boolean> }>('child');

const currentmatter = computed((): Component | null => {
  if (routeIs.Folders || (routeIs.FolderShare && initstate.shareType === 'folder')) {
    return markRaw(FolderDynamicTopMatter);
  } else if (routeIs.Places) {
    return markRaw(PlacesDynamicTopMatterVue);
  } else if (routeIs.Albums) {
    return markRaw(AlbumDynamicTopMatter);
  } else if (routeIs.Base && config.enable_top_memories) {
    return markRaw(OnThisDay);
  }

  return null;
});

/** Get view name for dynamic top matter */
const viewName = computed((): string => {
  // Show album name for album view
  if (routeIs.Albums) {
    return strings.albumDisplayName(route.params.name?.toString() ?? String());
  }

  // Show share name for public shares, except for folder share,
  // because the name is already present in the breadcrumbs
  if (routeIs.Public && !routeIs.FolderShare) {
    return initstate.shareTitle;
  }

  // Only static top matter for these routes
  if (routeIs.Tags || routeIs.People || routeIs.Places) {
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
