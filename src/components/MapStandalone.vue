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

<script setup lang="ts">
import { ref, computed } from 'vue';
import { LMap, LTileLayer, LMarker, LIcon } from '@vue-leaflet/vue-leaflet';
import { latLngBounds } from 'leaflet';

import { useUserConfig } from '@services/user-config';
import staticConfig from '@services/static-config';

import 'leaflet/dist/leaflet.css';
import 'leaflet-edgebuffer';

const OSM_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; <a target="_blank" href="http://osm.org/copyright">OpenStreetMap</a> contributors';

const { config } = useUserConfig();

const props = withDefaults(
  defineProps<{
    center?: [number, number];
    zoom?: number;
    pins?: [number, number][];
    scrollWheelZoom?: boolean;
  }>(),
  {
    zoom: 14,
    scrollWheelZoom: false,
  },
);

const emit = defineEmits<{
  ready: [map: NonNullable<InstanceType<typeof LMap>['leafletObject']>];
  moveend: [];
  zoomend: [];
}>();

/** Main map instance and configuration */
const map = ref<InstanceType<typeof LMap>>();
const mapOptions = computed(() => ({
  maxBounds: latLngBounds([-90, -180], [90, 180]),
  maxBoundsViscosity: 0.9,
  scrollWheelZoom: props.scrollWheelZoom,
}));

/** Static pins configuration */
const markerOptions = {
  interactive: false,
  keyboard: false,
};

/** Tile layer configuration */
const tileServer = computed(() => {
  const tiles = staticConfig.getSync('map_tile_servers') || [];
  return tiles.find((t) => t.url === config.map_tile_server_url);
});
const tileurl = computed(() => {
  return config.map_tile_server_url || OSM_TILE_URL;
});
const attribution = computed(() => {
  return tileServer.value?.attribution || OSM_ATTRIBUTION;
});
const tileLayerOptions = computed(() => ({
  referrerPolicy: 'origin',
  maxZoom: tileServer.value?.maxZoom ?? 19,
  maxNativeZoom: tileServer.value?.maxZoom ?? 19,
}));

function onReady(map: NonNullable<InstanceType<typeof LMap>['leafletObject']>) {
  emit('ready', map);
}

function getMap() {
  return map.value?.leafletObject;
}

defineExpose({ getMap });
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
    color: var(--color-text-maxcontrast);
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
