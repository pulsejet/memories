<template>
  <div class="map-matter">
    <l-map
      class="map"
      ref="map"
      :zoom="zoom"
      :minZoom="2"
      @moveend="refresh"
      @zoomend="refresh"
    >
      <l-tile-layer :url="url" :attribution="attribution" />
      <l-marker
        v-for="cluster in clusters"
        :key="cluster.center.toString()"
        :lat-lng="cluster.center"
      >
        <l-popup :content="cluster.count.toString()" />
      </l-marker>
    </l-map>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { LMap, LTileLayer, LMarker, LPopup } from "vue2-leaflet";
import { Icon } from "leaflet";

import { API } from "../../services/API";
import axios from "axios";

import "leaflet/dist/leaflet.css";

const TILE_URL = "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png";
const ATTRIBUTION =
  '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';

type IMarkerCluster = {
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
</style>
