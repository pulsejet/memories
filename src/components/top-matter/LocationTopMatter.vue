<template>
  <div class="location-top-matter">
    <l-map
      style="height: 100%; width: 100%; margin-right: 3.5em; z-index: 0"
      :zoom="zoom"
      ref="map"
      @moveend="updateMapAndTimeline"
      @zoomend="updateMapAndTimeline"
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
import { defineComponent, PropType } from "vue";
import { LMap, LTileLayer, LMarker, LPopup } from "vue2-leaflet";
import Vue2LeafletMarkerCluster from "vue2-leaflet-markercluster";
import "leaflet/dist/leaflet.css";
import { IPhoto, MarkerClusters } from "../../types";

import { Icon } from "leaflet";
import axios from "axios";
import { API } from "../../services/API";

type D = Icon.Default & {
  _getIconUrl?: string;
};

delete (Icon.Default.prototype as D)._getIconUrl;

Icon.Default.mergeOptions({
  iconRetinaUrl: require("leaflet/dist/images/marker-icon-2x.png"),
  iconUrl: require("leaflet/dist/images/marker-icon.png"),
  shadowUrl: require("leaflet/dist/images/marker-shadow.png"),
});

export default defineComponent({
  name: "LocationTopMatter",
  components: {
    LMap,
    LTileLayer,
    LMarker,
    LPopup,
    "v-marker-cluster": Vue2LeafletMarkerCluster,
  },

  data: () => ({
    name: "locations", // add for test
    url: "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    attribution:
      '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors',
    zoom: 1,
    clusters: [] as MarkerClusters[],
  }),

  watch: {
    $route: function (from: any, to: any) {
      this.createMatter();
    },
  },

  mounted() {
    this.createMatter();
    this.updateMapAndTimeline();
  },

  computed: {
    getPhotos() {
      let photos: IPhoto[] = [];
      return photos;
    },
  },

  methods: {
    createMatter() {
      this.name = <string>this.$route.params.name || "";
    },

    async updateMapAndTimeline() {
      let map = this.$refs.map as LMap;
      let boundary = map.mapObject.getBounds();
      let minLat = boundary.getSouth();
      let maxLat = boundary.getNorth();
      let minLng = boundary.getWest();
      let maxLng = boundary.getEast();
      let zoomLevel = map.mapObject.getZoom().toString();

      this.$parent.$emit("updateBoundary", {
        minLat: minLat,
        maxLat: maxLat,
        minLng: minLng,
        maxLng: maxLng,
      });

      let mapWidth = maxLat - minLat;
      let mapHeight = maxLng - minLng;
      const query = new URLSearchParams();
      // Show clusters correctly while draging the map
      query.set("minLat", (minLat - mapWidth).toString());
      query.set("maxLat", (maxLat + mapWidth).toString());
      query.set("minLng", (minLng - mapHeight).toString());
      query.set("maxLng", (maxLng + mapHeight).toString());
      query.set("zoom", zoomLevel);

      const url = API.Q(API.CLUSTERS(), query);
      const res = await axios.get(url);
      this.clusters = res.data;
    },
  },
});
</script>

<style lang="scss" scoped>
@import "~leaflet/dist/leaflet.css";
@import "~leaflet.markercluster/dist/MarkerCluster.css";
@import "~leaflet.markercluster/dist/MarkerCluster.Default.css";

.location-top-matter {
  display: flex;
  vertical-align: middle;
  height: 20em;
}
</style>
