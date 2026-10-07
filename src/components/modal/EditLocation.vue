<template>
  <div class="outer">
    <div class="lat-lon">
      <div class="coords">
        <span>{{ loc }}</span> {{ dirty ? '*' : '' }}
      </div>

      <div class="action">
        <NcActions :inline="2">
          <NcActionButton v-if="dirty" :aria-label="t('memories', 'Reset')" @click="reset()" :disabled="disabled">
            {{ t('memories', 'Reset') }}
            <template #icon> <UndoIcon :size="20" /> </template>
          </NcActionButton>

          <NcActionButton
            v-if="lat && lon"
            :aria-label="t('memories', 'Remove location')"
            @click="clear()"
            :disabled="disabled"
          >
            {{ t('memories', 'Remove location') }}
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <NcTextField
      v-model="searchBar"
      :label="t('memories', 'Search')"
      :placeholder="t('memories', 'Search location / landmark')"
      :disabled="disabled"
      trailing-button-icon="arrowEnd"
      :show-trailing-button="searchBar.length > 0 && !loading"
      @trailing-button-click="search"
      @keypress.enter="search"
    >
      <MagnifyIcon :size="16" />
    </NcTextField>

    <div class="osm-attribution" v-if="isNominatim">
      Powered by
      <a :href="searchBase" target="_blank">Nominatim</a>
      &copy;
      <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>
      contributors
    </div>

    <XLoadingIcon class="loading-spinner" v-if="loading" />

    <ul v-if="options.length > 0">
      <li
        v-for="option in options"
        :key="option.osm_id"
        :disabled="disabled"
        @click="select(option)"
        @keypress.enter="select(option)"
        tabindex="0"
      >
        {{ option.display_name }}
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, defineAsyncComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@services/utils/dialog';
import { config } from '@services/user-config';
import { t } from '@services/l10n';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import type { IPhoto } from '@typings';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import UndoIcon from 'vue-material-design-icons/UndoVariant.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

type NLocation = {
  osm_id: number;
  type?: string;
  icon?: string;
  display_name: string;
  lat: string;
  lon: string;
};

const props = defineProps<{
  photos: IPhoto[];
  disabled?: boolean;
}>();

const dirty = ref(false);
const lat = ref<number | null>(null);
const lon = ref<number | null>(null);
const searchBar = ref('');
const loading = ref(false);

const options = ref<NLocation[]>([]);

const loc = computed(() => {
  if (lat.value && lon.value) {
    return `${lat.value.toFixed(6)}, ${lon.value.toFixed(6)}`;
  }
  return t('memories', 'No coordinates');
});

const searchBase = computed(() => config.places_search_url.trim());
const isNominatim = computed(() => searchBase.value.toLowerCase().includes('nominatim'));

onMounted(() => {
  reset();
});

function reset() {
  dirty.value = false;
  const photos = props.photos as IPhoto[];

  let latSum = 0,
    lonSum = 0,
    count = 0;
  for (const photo of photos) {
    const exif = photo.imageInfo?.exif;
    if (!exif) {
      continue;
    }

    if (exif.GPSLatitude && exif.GPSLongitude) {
      latSum += Number(exif.GPSLatitude);
      lonSum += Number(exif.GPSLongitude);
      count++;
    }
  }

  if (count > 0) {
    lat.value = latSum / count;
    lon.value = lonSum / count;
  } else {
    lat.value = lon.value = null;
  }
}

async function search() {
  if (loading.value || searchBar.value.length === 0) {
    return;
  }

  // Check if searchbar is already a coordinate
  const coords = searchBar.value.split(',');
  if (coords.length === 2) {
    const coordLat = Number(coords[0].trim());
    const coordLon = Number(coords[1].trim());
    if (!isNaN(coordLat) && !isNaN(coordLon)) {
      return select({
        osm_id: 0,
        display_name: `${coordLat.toFixed(6)}, ${coordLon.toFixed(6)}`,
        lat: coordLat.toFixed(6),
        lon: coordLon.toFixed(6),
      });
    }
  }

  // No search provider configured.
  if (!searchBase.value) return;

  loading.value = true;
  const q = window.encodeURIComponent(searchBar.value);
  try {
    const response = await axios.get<NLocation[]>(`${searchBase.value}/search?q=${q}&format=jsonv2`);
    options.value = response.data.filter((x) => x.lat && x.lon && x.display_name);
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Failed to search for location.'));
  } finally {
    loading.value = false;
  }
}

function clear() {
  dirty.value = true;
  lat.value = 0;
  lon.value = 0;
}

function select(option: NLocation) {
  dirty.value = true;
  lat.value = Number(option.lat);
  lon.value = Number(option.lon);
  options.value = [];
  searchBar.value = '';
}

function result() {
  if (!dirty.value || lat.value === null || lon.value === null) return null;

  const resultLat = lat.value.toFixed(6);
  const resultLon = lon.value.toFixed(6);

  // Exiftool is actually supposed to pick up the reference from
  // a signed set of coordinates: https://exiftool.org/faq.html#Q14
  // But it doesn't seem to work for some very specific files, so
  // we'll just set it manually to N/S and E/W
  return {
    GPSLatitude: resultLat,
    GPSLongitude: resultLon,
    GPSLatitudeRef: lat.value >= 0 ? 'N' : 'S',
    GPSLongitudeRef: lon.value >= 0 ? 'E' : 'W',
    GPSCoordinates: `${resultLat}, ${resultLon}`,
  };
}

defineExpose({ result });
</script>

<style scoped lang="scss">
.outer {
  .lat-lon {
    display: flex;
    padding: 4px;
    margin-bottom: -14px;

    > .coords {
      display: inline-block;
      flex-grow: 1;
      min-height: 36px;

      > span {
        user-select: all;
      }
    }

    > .action {
      margin-top: -10px;
      margin-left: 2px;
      > * {
        cursor: pointer;
      }
    }
  }

  .osm-attribution {
    margin: 0 4px;
    font-size: 0.65em;
    a {
      color: var(--color-primary);
    }
  }

  .loading-spinner {
    margin: 10px;
  }

  ul {
    margin: 10px 0;
    max-height: 200px;
    overflow-y: auto;

    li {
      font-size: 0.9em;
      padding: 5px 10px;
      margin: 2px 0;
      cursor: pointer;

      &:hover {
        background-color: var(--color-background-hover);
      }
    }
  }
}
</style>
