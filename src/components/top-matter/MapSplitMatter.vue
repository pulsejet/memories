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
      @moveend="refresh"
      @zoomend="refresh"
      :options="mapOptions"
    >
      <LTileLayer :url="tileurl" :attribution="attribution" :noWrap="true" />
      <LMarker
        v-for="cluster in clusters"
        :key="cluster.id"
        :lat-lng="cluster.center"
        @click="zoomTo(cluster)"
      >
        <LIcon :icon-anchor="[24, 24]" :className="clusterIconClass(cluster)">
          <div class="preview">
            <div class="count" v-if="cluster.count > 1">
              {{ cluster.count }}
            </div>
            <img
              :src="clusterPreviewUrl(cluster)"
              :class="[
                'thumb-important',
                `memories-thumb-${cluster.preview.fileid}`,
              ]"
            />
          </div>
        </LIcon>
      </LMarker>
    </LMap>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { LMap, LTileLayer, LMarker, LPopup, LIcon } from "vue2-leaflet";
import { latLngBounds } from "leaflet";
import { IPhoto } from "../../types";

import { API } from "../../services/API";
import { getPreviewUrl } from "../../services/FileUtils";
import axios from "@nextcloud/axios";
import * as utils from "../../services/Utils";

import "leaflet/dist/leaflet.css";

const OSM_TILE_URL = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
const OSM_ATTRIBUTION =
  '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';
const STAMEN_URL = `https://stamen-tiles-{s}.a.ssl.fastly.net/terrain-background/{z}/{x}/{y}{r}.png`;
const STAMEN_ATTRIBUTION = `Map tiles by <a href="http://stamen.com">Stamen Design</a>, under <a href="http://creativecommons.org/licenses/by/3.0">CC BY 3.0</a>. Data by <a href="http://openstreetmap.org">OpenStreetMap</a>, under <a href="http://www.openstreetmap.org/copyright">ODbL</a>.`;

// CSS transition time for zooming in/out cluster animation
const CLUSTER_TRANSITION_TIME = 300;

type IMarkerCluster = {
  id?: number;
  center: [number, number];
  count: number;
  preview?: IPhoto;
  dummy?: boolean;
};

export default defineComponent({
  name: "MapSplitMatter",
  components: {
    LMap,
    LTileLayer,
    LMarker,
    LPopup,
    LIcon,
  },

  data: () => ({
    zoom: 2,
    mapOptions: {
      maxBounds: latLngBounds([-90, -180], [90, 180]),
      maxBoundsViscosity: 0.9,
    },
    clusters: [] as IMarkerCluster[],
    animMarkers: false,
  }),

  mounted() {
    const map = this.$refs.map as LMap;

    // Make sure the zoom control doesn't overlap with the navbar
    map.mapObject.zoomControl.setPosition("topright");

    // Initialize
    this.refresh();
  },

  computed: {
    tileurl() {
      return this.zoom >= 5 ? OSM_TILE_URL : STAMEN_URL;
    },

    attribution() {
      return this.zoom >= 5 ? OSM_ATTRIBUTION : STAMEN_ATTRIBUTION;
    },
  },

  methods: {
    async refresh() {
      const map = this.$refs.map as LMap;

      // Get boundaries of the map
      const boundary = map.mapObject.getBounds();
      let minLat = boundary.getSouth();
      let maxLat = boundary.getNorth();
      let minLon = boundary.getWest();
      let maxLon = boundary.getEast();

      // Set query parameters to route if required
      const s = (x: number) => x.toFixed(6);
      const bounds = () =>
        `${s(minLat)},${s(maxLat)},${s(minLon)},${s(maxLon)}`;

      // Zoom level
      const oldZoom = this.zoom;
      const newZoom = Math.round(map.mapObject.getZoom());
      const zoomStr = newZoom.toString();
      this.zoom = newZoom;

      // Check if we already have the data
      if (this.$route.query.b === bounds() && this.$route.query.z === zoomStr) {
        return;
      }
      this.$router.replace({
        query: {
          b: bounds(),
          z: zoomStr,
        },
      });

      // Extend bounds by 25% beyond the map
      const latDiff = Math.abs(maxLat - minLat);
      const lonDiff = Math.abs(maxLon - minLon);
      minLat -= latDiff * 0.25;
      maxLat += latDiff * 0.25;
      minLon -= lonDiff * 0.25;
      maxLon += lonDiff * 0.25;

      // Show clusters correctly while draging the map
      const query = new URLSearchParams();
      query.set("bounds", bounds());
      query.set("zoom", zoomStr);

      // Make API call
      const url = API.Q(API.MAP_CLUSTERS(), query);
      const res = await axios.get(url);

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

    clusterPreviewUrl(cluster: IMarkerCluster) {
      return getPreviewUrl(cluster.preview, false, 256);
    },

    clusterIconClass(cluster: IMarkerCluster) {
      return cluster.dummy ? "dummy" : "";
    },

    zoomTo(cluster: IMarkerCluster) {
      // At high zoom levels, open the photo
      if (this.zoom >= 12 && cluster.preview) {
        cluster.preview.key = cluster.preview.fileid.toString();
        this.$router.push(utils.getViewerRoute(cluster.preview));
        return;
      }

      // Zoom in
      const map = this.$refs.map as LMap;
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
}

.preview {
  width: 48px;
  height: 48px;
  background-color: rgba(0, 0, 0, 0.3);
  border-radius: 5px;
  position: relative;
  transition: transform 0.2s;

  &:hover {
    transform: scale(1.8);
  }

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 5px;
    cursor: pointer;
  }

  .count {
    position: absolute;
    top: 0;
    right: 0;
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

// Dark mode
$darkFilter: invert(1) grayscale(1) brightness(1.3) contrast(1.3);
.leaflet-tile-pane {
  body[data-theme-dark] &,
  body[data-theme-dark-highcontrast] & {
    filter: $darkFilter;
  }

  @media (prefers-color-scheme: dark) {
    body[data-theme-default] & {
      filter: $darkFilter;
    }
  }
}
</style>
