<template>
  <div class="container no-user-select cluster-view">
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid ref="grid" :items="items" :minCols="minCols" :maxSize="maxSize" :focus="true">
      <template #before>
        <DynamicTopMatter class="cv-dtm" ref="dtm" />
      </template>
    </ClusterGrid>
  </div>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { until } from '@vueuse/core';

import { routeIs } from '@services/router';
import { useRouteState } from '@services/route-state';
import { isAbortError, raceWithAbort, useAbort } from '@services/utils/abort-vue';
import TopMatter from '@components/top-matter/TopMatter.vue';
import ClusterGrid from '@components/ClusterGrid.vue';
import EmptyContent from '@components/top-matter/EmptyContent.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import { config } from '@services/user-config';
import * as dav from '@services/dav';

import type { ICluster } from '@typings';

const route = useRoute();
const dtmRef = useTemplateRef<InstanceType<typeof DynamicTopMatter>>('dtm');
const gridRef = useTemplateRef<VueHTMLComponent>('grid');
const items = ref<ICluster[]>([]);
const loading = ref(0);
const abort = useAbort();

useRouteState({
  state: { items },
  scroll: { gridRef },
});

const minCols = computed(() => (routeIs.Albums ? 2 : 3));
const maxSize = computed(() => (routeIs.Albums ? 250 : 180));

async function fetchClusters(signal: AbortSignal): Promise<ICluster[]> {
  if (routeIs.Albums) {
    return await dav.getAlbums({ signal });
  } else if (routeIs.Tags) {
    return await dav.getTags({ signal });
  } else if (routeIs.Recognize) {
    return await dav.getFaceList('recognize', { signal });
  } else if (routeIs.FaceRecognition) {
    return await dav.getFaceList('facerecognition', { signal });
  } else if (routeIs.Places) {
    return await dav.getPlaces({ signal });
  } else {
    return [];
  }
}

async function refresh() {
  const signal = abort.renew();

  try {
    loading.value++;

    // Refresh the DTM in parallel with loading our own data,
    // but wait for it to complete to avoid glitches.
    const [, newItems] = await Promise.all([
      raceWithAbort(signal, until(() => dtmRef.value?.isReady).toBeTruthy({ timeout: 2000 })),
      fetchClusters(signal),
    ]);
    signal.throwIfAborted();
    items.value = newItems;
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
  } finally {
    loading.value--;
  }
}

onMounted(refresh);
watch(() => route.path, refresh, { flush: 'post' });
watch(config, refresh);
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
