<template>
  <div
    :class="{
      'map-matter': true,
      'anim-markers': animMarkers,
    }"
  >
    <LMap
      class="map"
      ref="map"
      :crossOrigin="true"
      :zoom="zoom"
      :minZoom="2"
      @moveend="refreshDebounced"
      @zoomend="refreshDebounced"
      :options="mapOptions"
    >
      <LTileLayer :url="tileurl" :attribution="attribution" :noWrap="true" />
      <LMarker v-for="cluster of clusters" :key="cluster.id" :lat-lng="cluster.center" @click="zoomTo(cluster)">
        <LIcon :icon-anchor="[24, 24]" :className="clusterIconClass(cluster)">
          <div class="preview">
            <div class="count top-left" v-if="cluster.count > 1">
              {{ cluster.count }}
            </div>
            <XImg
              v-once
              :src="clusterPreviewUrl(cluster)"
              :class="['thumb-important', `memories-thumb-${cluster.preview.fileid}`]"
            />
          </div>
        </LIcon>
      </LMarker>
    </LMap>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { LMap, LTileLayer, LMarker, LPopup, LIcon } from '@vue-leaflet/vue-leaflet';
import { latLngBounds, Icon } from 'leaflet';

import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

import 'leaflet/dist/leaflet.css';
import 'leaflet-edgebuffer';

const OSM_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';

// CSS transition time for zooming in/out cluster animation
const CLUSTER_TRANSITION_TIME = 300;

type IMarkerCluster = {
  id: number;
  center: [number, number];
  count: number;
  preview: IPhoto;
  dummy?: boolean;
};

delete (<any>Icon.Default.prototype)._getIconUrl;

Icon.Default.mergeOptions({
  iconRetinaUrl: require('leaflet/dist/images/marker-icon-2x.png'),
  iconUrl: require('leaflet/dist/images/marker-icon.png'),
  shadowUrl: require('leaflet/dist/images/marker-shadow.png'),
});

export default defineComponent({
  name: 'MapSplitMatter',
  components: {
    LMap,
    LTileLayer,
    LMarker,
    LPopup,
    LIcon,
  },

  data: () => ({
    zoom: 2,
    oldZoom: 2,
    mapOptions: {
      maxBounds: latLngBounds([-90, -180], [90, 180]),
      maxBoundsViscosity: 0.9,
    },
    clusters: [] as IMarkerCluster[],
    animMarkers: false,
  }),

  mounted() {
    // Make sure the zoom control doesn't overlap with the navbar
    // this.refs.map.mapObject.zoomControl.setPosition('topright');

    // Initialize
    this.initialize();
  },

  created() {
    utils.bus.on('memories:window:resize', this.handleContainerResize);
  },

  beforeDestroy() {
    utils.bus.off('memories:window:resize', this.handleContainerResize);
  },

  computed: {
    refs() {
      return this.$refs as {
        map: InstanceType<typeof LMap>;
      };
    },

    tileurl() {
      return OSM_TILE_URL;
    },

    attribution() {
      return OSM_ATTRIBUTION;
    },
  },

  watch: {
    $route(curr, old) {
      if (curr.query.b === old.query.b && curr.query.z === old.query.z) return;
      this.initialize(true);
    },
  },

  methods: {
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
        const map = this.refs.map;
        const pos = init?.data?.pos;
        if (!pos?.lat || !pos?.lon) {
          throw new Error('No position data');
        }

        // This will trigger route change -> fetchClusters
        map.mapObject.setView([pos.lat, pos.lon], 11);
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
      const map = this.refs.map;
      if (!map || !map.mapObject) return;

      // Get boundaries of the map
      const boundary = map.mapObject.getBounds();
      let minLat = boundary.getSouth();
      let maxLat = boundary.getNorth();
      let minLon = boundary.getWest();
      let maxLon = boundary.getEast();

      // Set query parameters to route if required
      const bounds = this.boundsToStr({ minLat, maxLat, minLon, maxLon });

      // Zoom level
      this.zoom = Math.round(map.mapObject.getZoom());

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
      const zoom = this.$route.query.z as string;
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
      const res = await axios.get(url);
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
      const bounds = (this.$route.query.b as string).split(',');
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
      const map = this.refs.map;
      const { minLat, maxLat, minLon, maxLon } = this.boundsFromQuery();
      map.mapObject.fitBounds([
        [minLat, minLon],
        [maxLat, maxLon],
      ]);
    },

    clusterPreviewUrl(cluster: IMarkerCluster) {
      return utils.getPreviewUrl({
        photo: cluster.preview,
        msize: 256,
      });
    },

    clusterIconClass(cluster: IMarkerCluster) {
      return cluster.dummy ? 'dummy' : '';
    },

    zoomTo(cluster: IMarkerCluster) {
      // At high zoom levels, open the photo
      if (this.zoom >= 12 && cluster.preview) {
        cluster.preview.key = cluster.preview.fileid.toString();
        _m.viewer.open(cluster.preview);
        return;
      }

      // Zoom in
      const map = this.refs.map;
      const factor = globalThis.innerWidth >= 768 ? 2 : 1;
      const zoom = map.mapObject.getZoom() + factor;
      map.mapObject.setView(cluster.center, zoom, { animate: true });
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

    getGridMap(clusters: IMarkerCluster[], zoom: number) {
      const gridMap = new Map<string, IMarkerCluster>();
      for (const cluster of clusters) {
        const key = this.getGridKey(cluster.center, zoom);
        gridMap.set(key, cluster);
      }
      return gridMap;
    },

    async setClustersZoomIn(clusters: IMarkerCluster[], oldZoom: number) {
      // Create GID-map for old clusters
      const oldClusters = this.getGridMap(this.clusters, oldZoom);

      // Dummy clusters to animate markers
      const dummyClusters: IMarkerCluster[] = [];

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

    async setClustersZoomOut(clusters: IMarkerCluster[]) {
      // Get GID-map for new clusters
      const newClustersGid = this.getGridMap(clusters, this.zoom);

      // Get ID-map for new clusters
      const newClustersId = new Map<number, IMarkerCluster>();
      for (const cluster of clusters) {
        newClustersId.set(cluster.id, cluster);
      }

      // Dummy clusters to animate markers
      const dummyClusters: IMarkerCluster[] = [...clusters];

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
      this.refs.map?.mapObject?.invalidateSize(true);
    },
  },
});
</script>

<style lang="scss" scoped>
.map-matter {
  height: 100%;
  width: 100%;
}

.map {
  height: 100%;
  width: 100%;
  margin: 0;
  z-index: 0;
  background-color: var(--color-background-dark);

  :deep .leaflet-control-attribution {
    background-color: var(--color-background-dark);
    color: var(--color-text-light);
  }

  :deep .leaflet-bar a {
    background-color: var(--color-main-background);
    color: var(--color-main-text);

    &.leaflet-disabled {
      opacity: 0.6;
    }
  }
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
