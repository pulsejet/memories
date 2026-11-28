<template>
  <div class="container no-user-select cluster-view">
    <TopMatter />

    <NcTextField
      v-if="routeIs.Albums && items.length"
      class="cv-filter"
      v-model="filter"
      :label="t('memories', 'Filter albums by name')"
      :labelVisible="false"
      :placeholder="t('memories', 'Filter albums by name')"
      :showTrailingButton="!!filter"
      :trailingButtonLabel="t('memories', 'Clear')"
      @trailing-button-click="filter = String()"
    >
      <template #icon>
        <MagnifyIcon :size="20" />
      </template>
    </NcTextField>

    <EmptyContent v-if="!items.length && !loading" />

    <NcEmptyContent v-else-if="!filteredItems.length && !loading" :name="t('memories', 'No albums match your filter')">
      <template #icon>
        <MagnifyIcon />
      </template>
    </NcEmptyContent>

    <ClusterGrid
      ref="grid"
      :items="filteredItems"
      :minCols="minCols"
      :maxSize="maxSize"
      :focus="true"
      :loading="!!loading"
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
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import TopMatter from '@components/top-matter/TopMatter.vue';
import ClusterGrid from '@components/ClusterGrid.vue';
import EmptyContent from '@components/top-matter/EmptyContent.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';

import { t } from '@services/l10n';
import { config } from '@services/user-config';
import * as dav from '@services/dav';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

import type { ICluster } from '@typings';

const route = useRoute();
const routeIs = useRouteIs();
const dtmRef = useTemplateRef<InstanceType<typeof DynamicTopMatter>>('dtm');
const gridRef = useTemplateRef<VueHTMLComponent>('grid');
const items = ref<ICluster[]>([]);
const filter = ref('');
const loading = ref(0);
const abort = useAbort();

useRouteState({
  state: { items, filter },
  scroll: { gridRef },
});

/** Normalize text for case and accent insensitive matching */
function normalize(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase();
}

/** Items matching the album name filter */
const filteredItems = computed((): ICluster[] => {
  const query = normalize(filter.value.trim());
  if (!routeIs.Albums || !query) return items.value;
  return items.value.filter((item) => normalize(item.display_name || item.name).includes(query));
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

  .cv-filter {
    margin: 5px 60px 5px 10px;
    width: auto;
  }

  .cv-dtm {
    margin-bottom: 5px;
  }
}
</style>
