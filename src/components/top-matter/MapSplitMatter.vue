<template>
  <div
    ref="matter"
    :class="{
      'map-matter': true,
      'anim-markers': animMarkers,
    }"
  >
    <MapStandalone
      ref="standalone"
      :zoom="zoom"
      :scroll-wheel-zoom="true"
      @ready="onMapReady"
      @moveend="refreshDebounced"
      @zoomend="refreshDebounced"
    >
      <LMarker v-for="cluster of clusters" :key="cluster.id" :lat-lng="cluster.center" @click="zoomTo(cluster)">
        <LIcon :icon-anchor="[24, 24]" :className="clusterIconClass(cluster)">
          <div class="preview">
            <div class="count top-left" v-if="cluster.count > 1">
              {{ cluster.count }}
            </div>
            <XImg
              :src="clusterPreviewUrl(cluster)"
              :class="{
                'memories-thumb-important': true,
                [`memories-thumb-${cluster.preview.key}`]: lastClick === cluster.preview.fileid,
              }"
            />
          </div>
        </LIcon>
      </LMarker>
    </MapStandalone>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';
import { useResizeObserver } from '@vueuse/core';
import { useRoute, useRouter } from 'vue-router';
import { LMarker, LIcon } from '@vue-leaflet/vue-leaflet';

import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as utils from '@services/utils/common';
import { RenewingTimeout } from '@services/utils/renewing-timeout';

import MapStandalone from '@components/MapStandalone.vue';
import XImg from '@components/frame/XImg.vue';

import type { IMapCluster } from '@typings';

// CSS transition time for zooming in/out cluster animation
const CLUSTER_TRANSITION_TIME = 300;

defineOptions({
  name: 'MapSplitMatter',
});

const route = useRoute();
const router = useRouter();

const matter = useTemplateRef<HTMLDivElement>('matter');
const standalone = useTemplateRef<InstanceType<typeof MapStandalone>>('standalone');

const zoom = ref(2);
const oldZoom = ref(2);
const clusters = ref<IMapCluster[]>([]);
const animMarkers = ref(false);
const lastClick = ref(0); // fileid

useResizeObserver(matter, handleContainerResize);

onMounted(() => {
  if (standalone.value?.getMap()) {
    onMapReady();
  }
});

watch(
  () => [route.query.b, route.query.z],
  (curr, old) => {
    if (curr[0] === old[0] && curr[1] === old[1]) return;
    initialize(true);
  },
);

function onMapReady() {
  // Make sure the zoom control doesn't overlap with the navbar
  standalone.value!.getMap()!.zoomControl.setPosition('topright');

  // Initialize
  initialize();
}
/**
 * Get initial coordinates for display and set them.
 * Then fetch clusters.
 */
async function initialize(reinit: boolean = false) {
  // Check if we have bounds and zoom in query
  if (route.query.b && route.query.z) {
    if (!reinit) {
      setBoundsFromQuery();
    }
    return await fetchClusters();
  }

  // Otherwise, get location from server
  try {
    const init = await axios.get<{
      pos?: {
        lat?: number;
        lon?: number;
      };
    }>(API.MAP_INIT());

    // Init data contains position information
    const map = standalone.value!.getMap();
    const pos = init?.data?.pos;
    if (!pos?.lat || !pos?.lon) {
      throw new Error('No position data');
    }

    // This will trigger route change -> fetchClusters
    map!.setView([pos.lat, pos.lon], 11);
  } catch (e) {
    // We will initialize clusters anyway
  } finally {
    refresh();
  }
}

const refreshTimer = new RenewingTimeout();

async function refreshDebounced() {
  refreshTimer.set(refresh, 250);
}

async function refresh() {
  const map = standalone.value!.getMap();
  if (!map) return;

  // Get boundaries of the map
  const boundary = map.getBounds();
  let minLat = boundary.getSouth();
  let maxLat = boundary.getNorth();
  let minLon = boundary.getWest();
  let maxLon = boundary.getEast();

  // Set query parameters to route if required
  const bounds = boundsToStr({ minLat, maxLat, minLon, maxLon });

  // Zoom level
  zoom.value = Math.round(map.getZoom());

  // Construct query
  const query = {
    b: bounds,
    z: zoom.value.toString(),
  };

  // If the query parameters are the same, don't do anything
  if (route.query.b === query.b && route.query.z === query.z) {
    return;
  }

  // Add new query keeping old hash for viewer
  router.replace({
    query: query,
    hash: route.hash,
  });
}

async function fetchClusters() {
  const oldZoomVal = oldZoom.value;
  const qbounds = route.query.b;
  const zoomParam = route.query.z?.toString();
  const paramsChanged = () => route.query.b !== qbounds || route.query.z !== zoomParam;

  let { minLat, maxLat, minLon, maxLon } = boundsFromQuery();

  // Extend bounds by 25% beyond the map
  const latDiff = Math.abs(maxLat - minLat);
  const lonDiff = Math.abs(maxLon - minLon);
  minLat -= latDiff * 0.25;
  maxLat += latDiff * 0.25;
  minLon -= lonDiff * 0.25;
  maxLon += lonDiff * 0.25;

  // Get bounds with expanded margins
  const bounds = boundsToStr({ minLat, maxLat, minLon, maxLon });

  // Make API call
  const url = API.Q(API.MAP_CLUSTERS(), { bounds, zoom: zoomParam });

  // Params have changed, quit
  const res = await axios.get<IMapCluster[]>(url);
  if (paramsChanged()) return;

  // Mark currently loaded zoom level
  oldZoom.value = zoom.value;

  if (zoom.value > oldZoomVal) {
    setClustersZoomIn(res.data, oldZoomVal);
  } else if (zoom.value < oldZoomVal) {
    setClustersZoomOut(res.data);
  } else {
    clusters.value = res.data;
  }

  // Animate markers
  animateMarkers();
}

function boundsFromQuery() {
  const bounds = (route.query.b?.toString() ?? '').split(',');
  return {
    minLat: parseFloat(bounds[0]),
    maxLat: parseFloat(bounds[1]),
    minLon: parseFloat(bounds[2]),
    maxLon: parseFloat(bounds[3]),
  };
}

function boundsToStr({
  minLat,
  maxLat,
  minLon,
  maxLon,
}: {
  minLat: number;
  maxLat: number;
  minLon: number;
  maxLon: number;
}) {
  const s = (x: number) => x.toFixed(6);
  return `${s(minLat)},${s(maxLat)},${s(minLon)},${s(maxLon)}`;
}

function setBoundsFromQuery() {
  const map = standalone.value!.getMap();
  const { minLat, maxLat, minLon, maxLon } = boundsFromQuery();
  map!.fitBounds([
    [minLat, minLon],
    [maxLat, maxLon],
  ]);
}

function clusterPreviewUrl(cluster: IMapCluster) {
  return utils.getPreviewUrl({
    photo: cluster.preview,
    msize: 256,
  });
}

function clusterIconClass(cluster: IMapCluster) {
  return cluster.dummy ? 'dummy' : '';
}

function zoomTo(cluster: IMapCluster) {
  // At high zoom levels, open the photo
  if (zoom.value >= 12 && cluster.preview) {
    // Set the thum key and important class so this zooms in.
    // Reset it later so the next click is unambiguous.
    cluster.preview.key = cluster.preview.fileid.toString();
    lastClick.value = cluster.preview.fileid;
    setTimeout(() => (lastClick.value = 0), 500);
    // Open viewer with this photo.
    _m.viewer.open(cluster.preview);
    return;
  }

  // Zoom in
  const map = standalone.value!.getMap();
  const factor = globalThis.innerWidth >= 768 ? 2 : 1;
  const zoomVal = map!.getZoom() + factor;
  map!.setView(cluster.center, zoomVal, { animate: true });
}

function getGridKey(center: [number, number], zoomVal: number) {
  // Calcluate grid length
  const clusterDensity = 1;
  const oldGridLen = 180.0 / (2 ** zoomVal * clusterDensity);

  // Get map key
  const latGid = Math.floor(center[0] / oldGridLen);
  const lonGid = Math.floor(center[1] / oldGridLen);
  return `${latGid}-${lonGid}`;
}

function getGridMap(clustersVal: IMapCluster[], zoomVal: number) {
  const gridMap = new Map<string, IMapCluster>();
  for (const cluster of clustersVal) {
    const key = getGridKey(cluster.center, zoomVal);
    gridMap.set(key, cluster);
  }
  return gridMap;
}

async function setClustersZoomIn(clustersVal: IMapCluster[], oldZoomVal: number) {
  // Create GID-map for old clusters
  const oldClusters = getGridMap(clusters.value, oldZoomVal);

  // Dummy clusters to animate markers
  const dummyClusters: IMapCluster[] = [];

  // Iterate new clusters
  for (const cluster of clustersVal) {
    // Check if cluster already exists
    const key = getGridKey(cluster.center, oldZoomVal);
    const oldCluster = oldClusters.get(key);
    if (oldCluster) {
      // Copy cluster and set location to old cluster
      dummyClusters.push({
        ...cluster,
        center: oldCluster.center,
      });
    } else {
      // Just show it
      dummyClusters.push(cluster);
    }
  }

  // Set clusters
  clusters.value = dummyClusters;
  await nextTick();
  await new Promise((r) => setTimeout(r, 0));
  clusters.value = clustersVal;
}

async function setClustersZoomOut(clustersVal: IMapCluster[]) {
  // Get GID-map for new clusters
  const newClustersGid = getGridMap(clustersVal, zoom.value);

  // Get ID-map for new clusters
  const newClustersId = new Map<number, IMapCluster>();
  for (const cluster of clustersVal) {
    newClustersId.set(cluster.id, cluster);
  }

  // Dummy clusters to animate markers
  const dummyClusters: IMapCluster[] = [...clustersVal];

  // Iterate old clusters
  for (const oldCluster of clusters.value) {
    // Process only clusters that are not in the new clusters
    const newCluster = newClustersId.get(oldCluster.id);
    if (!newCluster) {
      // Get the new cluster at the same GID
      const key = getGridKey(oldCluster.center, zoom.value);
      const newCluster = newClustersGid.get(key);
      if (newCluster) {
        // No need to copy; it is gone anyway
        oldCluster.center = newCluster.center;
        oldCluster.dummy = true;
        dummyClusters.push(oldCluster);
      }
    }
  }

  // Set clusters
  clusters.value = dummyClusters;
  await new Promise((r) => setTimeout(r, CLUSTER_TRANSITION_TIME)); // wait for animation
  clusters.value = clustersVal;
}

async function animateMarkers() {
  animMarkers.value = true;
  await new Promise((r) => setTimeout(r, CLUSTER_TRANSITION_TIME)); // wait for animation
  animMarkers.value = false;
}

function handleContainerResize() {
  standalone.value?.getMap()?.invalidateSize(true);
}
</script>

<style lang="scss" scoped>
.map-matter {
  height: 100%;
  width: 100%;
}

.preview {
  width: 48px;
  height: 48px;
  background-color: rgba(0, 0, 0, 0.3);
  border-radius: 5px;
  position: relative;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.2);

  &:hover {
    box-shadow: 0 0 3px var(--color-primary);
  }

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 5px;
    cursor: pointer;
  }

  .count {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
    padding: 0 4px;
    border-radius: 5px;
    font-size: 0.8em;
  }
}
</style>

<style lang="scss">
.leaflet-marker-icon {
  .anim-markers & {
    transition: transform 0.3s ease;
  }

  &.dummy {
    z-index: -100000 !important;
  }

  &:hover {
    z-index: 100000 !important;
  }
}
</style>
