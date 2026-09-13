<template>
  <LMap
    ref="map"
    class="map-standalone-map"
    :center="center"
    :zoom="zoom"
    :minZoom="2"
    :maxZoom="tileLayerOptions.maxZoom"
    :options="mapOptions"
    :crossOrigin="true"
    @ready="onReady"
    @moveend="$emit('moveend')"
    @zoomend="$emit('zoomend')"
    @touchstart.stop
    @touchmove.stop
    @touchend.stop
    @touchcancel.stop
  >
    <!-- Main tile layer depending on user settings -->
    <LTileLayer :key="tileurl" :url="tileurl" :attribution="attribution" :noWrap="true" :options="tileLayerOptions" />

    <!-- Markers requested by parent -->
    <LMarker v-for="(pin, index) of pins ?? []" :key="index" :lat-lng="pin" :options="markerOptions">
      <LIcon :icon-size="[24, 24]" :icon-anchor="[12, 12]" :className="'map-standalone-icon'">
        <div class="pin" />
      </LIcon>
    </LMarker>

    <!-- Default slot is always rendered inside the map -->
    <slot />
  </LMap>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';
import { LMap, LTileLayer, LMarker, LIcon } from '@vue-leaflet/vue-leaflet';
import { latLngBounds } from 'leaflet';

import UserConfig from '@mixins/UserConfig';
import staticConfig from '@services/static-config';

import 'leaflet/dist/leaflet.css';
import 'leaflet-edgebuffer';

const OSM_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';

export default defineComponent({
  name: 'MapStandalone',
  mixins: [UserConfig],
  components: {
    LMap,
    LTileLayer,
    LMarker,
    LIcon,
  },
  emits: ['ready', 'moveend', 'zoomend'],

  props: {
    center: {
      type: Array as unknown as PropType<[number, number]>,
      required: false,
      default: undefined,
    },
    zoom: {
      type: Number,
      required: false,
      default: 14,
    },
    /** Simple pin markers; the default slot is always rendered inside the map too */
    pins: {
      type: Array as unknown as PropType<[number, number][]>,
      required: false,
      default: undefined,
    },
    scrollWheelZoom: {
      type: Boolean,
      required: false,
      default: false,
    },
  },

  data: () => ({
    markerOptions: {
      interactive: false,
      keyboard: false,
    },
  }),

  computed: {
    mapOptions() {
      return {
        maxBounds: latLngBounds([-90, -180], [90, 180]),
        maxBoundsViscosity: 0.9,
        scrollWheelZoom: this.scrollWheelZoom,
      };
    },

    tileServer() {
      const tiles = staticConfig.getSync('map_tile_servers') || [];
      return tiles.find((t) => t.url === this.config.map_tile_server_url);
    },

    tileurl(): string {
      return this.config.map_tile_server_url || OSM_TILE_URL;
    },

    attribution(): string {
      return this.tileServer?.attribution || OSM_ATTRIBUTION;
    },

    tileLayerOptions(): { referrerPolicy: string; maxZoom: number; maxNativeZoom: number } {
      return {
        referrerPolicy: 'origin',
        maxZoom: this.tileServer?.maxZoom ?? 19,
        maxNativeZoom: this.tileServer?.maxZoom ?? 19,
      };
    },
  },

  methods: {
    getMap() {
      const map = this.$refs.map as InstanceType<typeof LMap> | undefined;
      return map?.leafletObject;
    },

    onReady(map: NonNullable<InstanceType<typeof LMap>['leafletObject']>) {
      this.$emit('ready', map);
    },
  },
});
</script>

<style lang="scss" scoped>
.map-standalone-map {
  height: 100%;
  width: 100%;
  margin: 0;
  z-index: 0;
  background-color: var(--color-background-dark);

  :deep(.leaflet-control-attribution) {
    background-color: var(--color-background-dark);
    color: var(--color-text-light);
  }

  :deep(.leaflet-bar a) {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
  }

  :deep(.leaflet-bar a.leaflet-disabled) {
    opacity: 0.6;
  }

  :deep(.map-standalone-icon) {
    background: none;
    border: none;
  }
}

.pin {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background-color: var(--color-primary, #0082c9);
  border: 3px solid #fff;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
</style>
