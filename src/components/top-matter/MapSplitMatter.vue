<template>
  <div class="map-matter">
    <LMap
      class="map"
      ref="map"
      :crossOrigin="true"
      :zoom="zoom"
      :minZoom="2"
      @moveend="refresh"
      @zoomend="refresh"
    >
      <LTileLayer :url="tileurl" :attribution="attribution" />
      <LMarker
        v-for="cluster in clusters"
        :key="cluster.id"
        :lat-lng="cluster.center"
        @click="zoomTo(cluster)"
      >
        <LIcon :icon-anchor="[24, 24]">
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

type IMarkerCluster = {
  id?: number;
  u?: any;
  center: [number, number];
  count: number;
  preview?: IPhoto;
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
    clusters: [] as IMarkerCluster[],
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
      const minLat = boundary.getSouth();
      const maxLat = boundary.getNorth();
      const minLon = boundary.getWest();
      const maxLon = boundary.getEast();

      // Set query parameters to route if required
      const s = (x: number) => x.toFixed(6);
      const bounds = `${s(minLat)},${s(maxLat)},${s(minLon)},${s(maxLon)}`;
      this.zoom = Math.round(map.mapObject.getZoom());
      const zoom = this.zoom.toString();
      if (this.$route.query.b === bounds && this.$route.query.z === zoom) {
        return;
      }
      this.$router.replace({ query: { b: bounds, z: zoom } });

      // Show clusters correctly while draging the map
      const query = new URLSearchParams();
      query.set("bounds", bounds);
      query.set("zoom", zoom);

      // Make API call
      const url = API.Q(API.MAP_CLUSTERS(), query);
      const res = await axios.get(url);
      this.clusters = res.data;
    },

    clusterPreviewUrl(cluster: IMarkerCluster) {
      let url = getPreviewUrl(cluster.preview, false, 256);
      if (cluster.u) {
        url += `?u=${cluster.u}`;
      }
      return url;
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
    background-color: var(--color-primary-default);
    color: var(--color-primary-text);
    padding: 0 4px;
    border-radius: 5px;
    font-size: 0.8em;
  }
}
</style>

<style lang="scss">
.leaflet-marker-icon {
  animation: fade-in 0.2s;
}

// Show leaflet marker on top on hover
.leaflet-marker-icon:hover {
  z-index: 100000 !important;
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

@keyframes fade-in {
  0% {
    opacity: 0;
  }
  100% {
    opacity: 1;
  }
}
</style>
