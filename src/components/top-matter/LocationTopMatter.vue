<template>
    <div class="location-top-matter">
        <l-map style="height: 100%; width: 100%; margin-right: 3.5em; z-index: 0;" :zoom="zoom" ref="map"
            @moveend="getBoundary" @zoomend="getBoundary">
            <l-tile-layer :url="url" :attribution="attribution" />
            <v-marker-cluster>
            </v-marker-cluster>
        </l-map>
    </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from "vue";
import { LMap, LTileLayer, LMarker, LPopup } from "vue2-leaflet";
import Vue2LeafletMarkerCluster from "vue2-leaflet-markercluster";
import "leaflet/dist/leaflet.css"
import { IRow, IPhoto } from "../../types";

import { Icon } from 'leaflet';

type D = Icon.Default & {
    _getIconUrl?: string;
};

delete (Icon.Default.prototype as D)._getIconUrl;

Icon.Default.mergeOptions({
    iconRetinaUrl: require('leaflet/dist/images/marker-icon-2x.png'),
    iconUrl: require('leaflet/dist/images/marker-icon.png'),
    shadowUrl: require('leaflet/dist/images/marker-shadow.png'),
});

export default defineComponent({
    name: "LocationTopMatter",
    components: {
        LMap,
        LTileLayer,
        LMarker,
        LPopup,
        'v-marker-cluster': Vue2LeafletMarkerCluster,
    },

    data: () => ({
        name: "locations", // add for test
        url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution:
            '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors',
        zoom: 1,
    }),

    watch: {
        $route: function (from: any, to: any) {
            this.createMatter();
        },
    },

    mounted() {
        this.createMatter();
    },

    computed: {
        getPhotos() {
            let photos: IPhoto[] = [];
            return photos;
        }
    },

    methods: {
        createMatter() {
            this.name = <string>this.$route.params.name || "";
        },

        getBoundary() {
            let map = this.$refs.map as LMap;
            let boundary = map.mapObject.getBounds();
            this.$parent.$emit("updateBoundary", {
                minLat: boundary.getSouth(),
                maxLat: boundary.getNorth(),
                minLng: boundary.getWest(),
                maxLng: boundary.getEast(),
            });
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