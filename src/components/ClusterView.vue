<template>
  <div v-if="noParams" class="container no-user-select cluster-view">
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid :items="items" :minCols="minCols" :maxSize="maxSize" :focus="true">
      <template #before>
        <DynamicTopMatter class="cv-dtm" ref="dtm" />
      </template>
    </ClusterGrid>
  </div>

  <Timeline v-else />
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute } from 'vue-router';

import {
  useRouteIsAlbums,
  useRouteIsTags,
  useRouteIsRecognize,
  useRouteIsFaceRecognition,
  useRouteIsPlaces,
} from '@services/route-checker';
import TopMatter from '@components/top-matter/TopMatter.vue';
import ClusterGrid from '@components/ClusterGrid.vue';
import Timeline from '@components/Timeline.vue';
import EmptyContent from '@components/top-matter/EmptyContent.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { ICluster } from '@typings';

const route = useRoute();
const routeIsAlbums = useRouteIsAlbums();
const routeIsTags = useRouteIsTags();
const routeIsRecognize = useRouteIsRecognize();
const routeIsFaceRecognition = useRouteIsFaceRecognition();
const routeIsPlaces = useRouteIsPlaces();

const dtm = ref<InstanceType<typeof DynamicTopMatter>>();
const items = ref<ICluster[]>([]);
const loading = ref(0);

const noParams = computed(() => !route.params.name?.toString() && !route.params.user?.toString());
const minCols = computed(() => (routeIsAlbums.value ? 2 : 3));
const maxSize = computed(() => (routeIsAlbums.value ? 250 : 180));

async function fetchClusters(): Promise<ICluster[]> {
  if (routeIsAlbums.value) {
    return await dav.getAlbums();
  } else if (routeIsTags.value) {
    return await dav.getTags();
  } else if (routeIsRecognize.value) {
    return await dav.getFaceList('recognize');
  } else if (routeIsFaceRecognition.value) {
    return await dav.getFaceList('facerecognition');
  } else if (routeIsPlaces.value) {
    return await dav.getPlaces();
  } else {
    return [];
  }
}

async function refresh() {
  await nextTick();
  if (!noParams.value || !!loading.value) return;

  try {
    items.value = [];
    loading.value++;

    await nextTick();

    // Refresh the DTM in parallel with loading our own data,
    // but wait for it to complete to avoid glitches.
    const [, newItems] = await Promise.all([dtm.value?.refresh?.(), fetchClusters()]);
    items.value = newItems;
  } finally {
    loading.value--;
  }
}

onMounted(refresh);
watch(() => route.path, refresh);

utils.bus.on('memories:user-config-changed', refresh);
onBeforeUnmount(() => {
  utils.bus.off('memories:user-config-changed', refresh);
});
</script>

<style lang="scss" scoped>
.container {
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  position: relative;

  .cv-dtm {
    margin-bottom: 5px;
  }
}
</style>
