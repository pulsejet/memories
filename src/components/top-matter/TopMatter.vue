<template>
  <div
    class="top-matter-container timeline-scroller-gap"
    :class="{
      'dynamic-visible': dynamicVisible,
    }"
    v-if="currentmatter"
  >
    <component :is="currentmatter" />
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw, ref } from 'vue';
import { useRoute } from 'vue-router';

import FolderTopMatter from './FolderTopMatter.vue';
import ClusterTopMatter from './ClusterTopMatter.vue';
import FaceTopMatter from './FaceTopMatter.vue';
import AlbumTopMatter from './AlbumTopMatter.vue';
import PlacesTopMatter from './PlacesTopMatter.vue';

import initstate from '@services/init-state';
import * as utils from '@services/utils/common';

defineOptions({
  name: 'TopMatter',
});

const route = useRoute();

const dynamicVisible = ref(true);

utils.useBus('memories.recycler.scroll', onRecyclerScroll);

const currentmatter = computed(() => {
  switch (route.name) {
    case _m.routes.Folders.name:
      return markRaw(FolderTopMatter);
    case _m.routes.FolderShare.name:
      return initstate.shareType === 'folder' ? markRaw(FolderTopMatter) : null;
    case _m.routes.AlbumsList.name:
    case _m.routes.Albums.name:
      return markRaw(AlbumTopMatter);
    case _m.routes.PlacesList.name:
    case _m.routes.Places.name:
      return markRaw(PlacesTopMatter);
    case _m.routes.TagsList.name:
    case _m.routes.Tags.name:
      return markRaw(ClusterTopMatter);
    case _m.routes.RecognizeList.name:
    case _m.routes.Recognize.name:
    case _m.routes.FaceRecognitionList.name:
    case _m.routes.FaceRecognition.name:
      return markRaw(FaceTopMatter);
    default:
      return null;
  }
});

function onRecyclerScroll({ dynTopMatterVisible }: utils.BusEvent['memories.recycler.scroll']) {
  dynamicVisible.value = dynTopMatterVisible;
}
</script>

<style lang="scss" scoped>
.top-matter-container {
  position: relative;
  z-index: 200; // above scroller, below top-bar
  padding: 2px 0;
  background-color: var(--color-main-background);
  transition: box-shadow 0.2s ease-in-out;

  &:not(.dynamic-visible) {
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);
  }

  // Hide shadow if inside cluster view
  .cluster-view & {
    box-shadow: none;
  }

  @media (max-width: 768px) {
    padding-left: 10px; // extra space visual
  }

  > div {
    display: flex;
    vertical-align: middle;
  }

  :deep(.name) {
    overflow: hidden;
    text-overflow: ellipsis;
    padding-left: 10px;
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    white-space: nowrap;
    vertical-align: top;
    flex-grow: 1;
  }

  :deep(button + .name) {
    padding-left: 0;
  }

  :deep(.right-actions) {
    margin-right: 12px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }

    /**
     * Hide the actions when the selection manager is open.
     * Having two action bars is confusing.
     */
    .memories-timeline:has(.memories-top-bar) & {
      visibility: hidden;
    }

    span {
      cursor: pointer;
    }
  }

  :deep(button) {
    display: inline-block;
  }
}
</style>
