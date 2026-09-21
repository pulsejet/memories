<template>
  <div
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

<script lang="ts">
import { defineComponent } from 'vue';
import { LMarker, LIcon } from '@vue-leaflet/vue-leaflet';

import axios from '@nextcloud/axios';

import UserConfig from '@mixins/UserConfig';
import { API } from '@services/API';
import * as utils from '@services/utils';

import MapStandalone from '@components/MapStandalone.vue';
import XImg from '@components/frame/XImg.vue';

import type { IMapCluster } from '@typings';

// CSS transition time for zooming in/out cluster animation
const CLUSTER_TRANSITION_TIME = 300;

export default defineComponent({
  name: 'MapSplitMatter',
  mixins: [UserConfig],
  components: {
    MapStandalone,
    LMarker,
    LIcon,
    XImg,
  },

  data: () => ({
    zoom: 2,
    oldZoom: 2,
    clusters: [] as IMapCluster[],
    animMarkers: false,
    lastClick: 0, // fileid
  }),

  mounted() {
    if (this.refs().standalone?.getMap()) {
      this.onMapReady();
    }
  },

  created() {
    utils.bus.on('memories:window:resize', this.handleContainerResize);
  },

  beforeUnmount() {
    utils.bus.off('memories:window:resize', this.handleContainerResize);
  },

  watch: {
    $route(curr, old) {
      if (curr.query.b === old.query.b && curr.query.z === old.query.z) return;
      this.initialize(true);
    },
  },

  methods: {
    refs() {
      return this.$refs as {
        standalone: InstanceType<typeof MapStandalone>;
      };
    },

    onMapReady() {
      // Make sure the zoom control doesn't overlap with the navbar
      this.refs().standalone.getMap()!.zoomControl.setPosition('topright');

      // Initialize
      this.initialize();
    },
    /**
     * Get initial coordinates for display and set them.
     * Then fetch clusters.
     */
    async initialize(reinit: boolean = false) {
      // Check if we have bounds and zoom in query
      if (this.$route.query.b && this.$route.query.z) {
        if (!reinit) {
          this.setBoundsFromQuery();
        }
        return await this.fetchClusters();
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
        const map = this.refs().standalone.getMap();
        const pos = init?.data?.pos;
        if (!pos?.lat || !pos?.lon) {
          throw new Error('No position data');
        }

        // This will trigger route change -> fetchClusters
        map!.setView([pos.lat, pos.lon], 11);
      } catch (e) {
        // We will initialize clusters anyway
      } finally {
        this.refresh();
      }
    },

    async refreshDebounced() {
      utils.setRenewingTimeout(this, 'refreshTimer', this.refresh, 250);
    },

    async refresh() {
      const map = this.refs().standalone.getMap();
      if (!map) return;

      // Get boundaries of the map
      const boundary = map.getBounds();
      let minLat = boundary.getSouth();
      let maxLat = boundary.getNorth();
      let minLon = boundary.getWest();
      let maxLon = boundary.getEast();

      // Set query parameters to route if required
      const bounds = this.boundsToStr({ minLat, maxLat, minLon, maxLon });

      // Zoom level
      this.zoom = Math.round(map.getZoom());

      // Construct query
      const query = {
        b: bounds,
        z: this.zoom.toString(),
      };

      // If the query parameters are the same, don't do anything
      if (this.$route.query.b === query.b && this.$route.query.z === query.z) {
        return;
      }

      // Add new query keeping old hash for viewer
      this.$router.replace({
        query: query,
        hash: this.$route.hash,
      });
    },

    async fetchClusters() {
      const oldZoom = this.oldZoom;
      const qbounds = this.$route.query.b;
      const zoom = this.$route.query.z?.toString();
      const paramsChanged = () => this.$route.query.b !== qbounds || this.$route.query.z !== zoom;

      let { minLat, maxLat, minLon, maxLon } = this.boundsFromQuery();

      // Extend bounds by 25% beyond the map
      const latDiff = Math.abs(maxLat - minLat);
      const lonDiff = Math.abs(maxLon - minLon);
      minLat -= latDiff * 0.25;
      maxLat += latDiff * 0.25;
      minLon -= lonDiff * 0.25;
      maxLon += lonDiff * 0.25;

      // Get bounds with expanded margins
      const bounds = this.boundsToStr({ minLat, maxLat, minLon, maxLon });

      // Make API call
      const url = API.Q(API.MAP_CLUSTERS(), { bounds, zoom });

      // Params have changed, quit
      const res = await axios.get<IMapCluster[]>(url);
      if (paramsChanged()) return;

      // Mark currently loaded zoom level
      this.oldZoom = this.zoom;

      if (this.zoom > oldZoom) {
        this.setClustersZoomIn(res.data, oldZoom);
      } else if (this.zoom < oldZoom) {
        this.setClustersZoomOut(res.data);
      } else {
        this.clusters = res.data;
      }

      // Animate markers
      this.animateMarkers();
    },

    boundsFromQuery() {
      const bounds = (this.$route.query.b?.toString() ?? '').split(',');
      return {
        minLat: parseFloat(bounds[0]),
        maxLat: parseFloat(bounds[1]),
        minLon: parseFloat(bounds[2]),
        maxLon: parseFloat(bounds[3]),
      };
    },

    boundsToStr({
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
    },

    setBoundsFromQuery() {
      const map = this.refs().standalone.getMap();
      const { minLat, maxLat, minLon, maxLon } = this.boundsFromQuery();
      map!.fitBounds([
        [minLat, minLon],
        [maxLat, maxLon],
      ]);
    },

    clusterPreviewUrl(cluster: IMapCluster) {
      return utils.getPreviewUrl({
        photo: cluster.preview,
        msize: 256,
      });
    },

    clusterIconClass(cluster: IMapCluster) {
      return cluster.dummy ? 'dummy' : '';
    },

    zoomTo(cluster: IMapCluster) {
      // At high zoom levels, open the photo
      if (this.zoom >= 12 && cluster.preview) {
        // Set the thum key and important class so this zooms in.
        // Reset it later so the next click is unambiguous.
        cluster.preview.key = cluster.preview.fileid.toString();
        this.lastClick = cluster.preview.fileid;
        setTimeout(() => (this.lastClick = 0), 500);
        // Open viewer with this photo.
        _m.viewer.open(cluster.preview);
        return;
      }

      // Zoom in
      const map = this.refs().standalone.getMap();
      const factor = globalThis.innerWidth >= 768 ? 2 : 1;
      const zoom = map!.getZoom() + factor;
      map!.setView(cluster.center, zoom, { animate: true });
    },

    getGridKey(center: [number, number], zoom: number) {
      // Calcluate grid length
      const clusterDensity = 1;
      const oldGridLen = 180.0 / (2 ** zoom * clusterDensity);

      // Get map key
      const latGid = Math.floor(center[0] / oldGridLen);
      const lonGid = Math.floor(center[1] / oldGridLen);
      return `${latGid}-${lonGid}`;
    },

    getGridMap(clusters: IMapCluster[], zoom: number) {
      const gridMap = new Map<string, IMapCluster>();
      for (const cluster of clusters) {
        const key = this.getGridKey(cluster.center, zoom);
        gridMap.set(key, cluster);
      }
      return gridMap;
    },

    async setClustersZoomIn(clusters: IMapCluster[], oldZoom: number) {
      // Create GID-map for old clusters
      const oldClusters = this.getGridMap(this.clusters, oldZoom);

      // Dummy clusters to animate markers
      const dummyClusters: IMapCluster[] = [];

      // Iterate new clusters
      for (const cluster of clusters) {
        // Check if cluster already exists
        const key = this.getGridKey(cluster.center, oldZoom);
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
      this.clusters = dummyClusters;
      await this.$nextTick();
      await new Promise((r) => setTimeout(r, 0));
      this.clusters = clusters;
    },

    async setClustersZoomOut(clusters: IMapCluster[]) {
      // Get GID-map for new clusters
      const newClustersGid = this.getGridMap(clusters, this.zoom);

      // Get ID-map for new clusters
      const newClustersId = new Map<number, IMapCluster>();
      for (const cluster of clusters) {
        newClustersId.set(cluster.id, cluster);
      }

      // Dummy clusters to animate markers
      const dummyClusters: IMapCluster[] = [...clusters];

      // Iterate old clusters
      for (const oldCluster of this.clusters) {
        // Process only clusters that are not in the new clusters
        const newCluster = newClustersId.get(oldCluster.id);
        if (!newCluster) {
          // Get the new cluster at the same GID
          const key = this.getGridKey(oldCluster.center, this.zoom);
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
      this.clusters = dummyClusters;
      await new Promise((r) => setTimeout(r, CLUSTER_TRANSITION_TIME)); // wait for animation
      this.clusters = clusters;
    },

    async animateMarkers() {
      this.animMarkers = true;
      await new Promise((r) => setTimeout(r, CLUSTER_TRANSITION_TIME)); // wait for animation
      this.animMarkers = false;
    },

    handleContainerResize() {
      this.refs().standalone?.getMap()?.invalidateSize(true);
    },
  },
});
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
