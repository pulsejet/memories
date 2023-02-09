<template>
  <div class="map-matter">
    <LMap
      class="map"
      ref="map"
      :zoom="zoom"
      :minZoom="2"
      @moveend="refresh"
      @zoomend="refresh"
    >
      <LTileLayer :url="url" :attribution="attribution" />
      <LMarker
        v-for="cluster in clusters"
        :key="cluster.id"
        :lat-lng="cluster.center"
        @click="zoomTo(cluster.center)"
      >
        <LIcon :icon-anchor="[24, 24]">
          <div class="preview">
            <div class="count">{{ cluster.count }}</div>
            <img :src="clusterPreviewUrl(cluster)" />
          </div>
        </LIcon>
      </LMarker>
    </LMap>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { LMap, LTileLayer, LMarker, LPopup, LIcon } from "vue2-leaflet";
import { Icon } from "leaflet";

import { API } from "../../services/API";
import axios from "axios";

import "leaflet/dist/leaflet.css";

const TILE_URL = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
const ATTRIBUTION =
  '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';

type IMarkerCluster = {
  id?: number;
  center: [number, number];
  count: number;
};

Icon.Default.mergeOptions({
  iconRetinaUrl: require("leaflet/dist/images/marker-icon-2x.png"),
  iconUrl: require("leaflet/dist/images/marker-icon.png"),
  shadowUrl: require("leaflet/dist/images/marker-shadow.png"),
});

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
    url: TILE_URL,
    attribution: ATTRIBUTION,
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
      const zoom = map.mapObject.getZoom().toString();
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
      return API.MAP_CLUSTER_PREVIEW(cluster.id);
    },

    zoomTo(center: [number, number]) {
      const map = this.$refs.map as LMap;
      const zoom = map.mapObject.getZoom() + 2;
      map.mapObject.setView(center, zoom, { animate: true });
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
  background-color: #fff;
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
  transition: transform 0.2s;
  animation: fade-in 0.2s;
}

// Show leaflet marker on top on hover
.leaflet-marker-icon:hover {
  z-index: 100000 !important;
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
