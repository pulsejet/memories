<template>
  <div class="container no-user-select cluster-view">
    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid
      ref="grid"
      :items="items"
      :minCols="minCols"
      :maxSize="maxSize"
      :focus="true"
      :loading="!!loading"
      :selection="selectable ? selection : undefined"
      @select="toggleSelect"
    >
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

import { useRouteIs } from '@services/route-is';
import { useRouteState } from '@services/route-state';
import { isAbortError, raceWithAbort, useAbort } from '@services/utils/abort-vue';
import TopMatter from '@components/top-matter/TopMatter.vue';
import ClusterGrid from '@components/ClusterGrid.vue';
import EmptyContent from '@components/top-matter/EmptyContent.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import { provideClusterSelection } from '@components/cluster-selection';

import { config } from '@services/user-config';
import { fragment } from '@services/route-fragment';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { ICluster } from '@typings';

const route = useRoute();
const routeIs = useRouteIs();
const dtmRef = useTemplateRef<InstanceType<typeof DynamicTopMatter>>('dtm');
const gridRef = useTemplateRef<VueHTMLComponent>('grid');
const items = ref<ICluster[]>([]);
const loading = ref(0);
const abort = useAbort();
const selection = provideClusterSelection(refresh);

useRouteState({
  state: { items },
  scroll: { gridRef },
});

const minCols = computed(() => (routeIs.Albums ? 2 : 3));
const maxSize = computed(() => (routeIs.Albums ? 250 : 180));
const selectable = computed(() => routeIs.Recognize || routeIs.FaceRecognition);

function toggleSelect(item: ICluster) {
  const rest = selection.value.filter((c) => c.cluster_id !== item.cluster_id);
  selection.value = rest.length === selection.value.length ? [...rest, item] : rest;
}

utils.useBus('memories:fragment:pop:selection', () => (selection.value = []));
watch(
  () => selection.value.length > 0,
  (value) => fragment.if(value, fragment.types.selection),
);

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
    selection.value = [];
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
