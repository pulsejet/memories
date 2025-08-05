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
      :value.sync="searchBar"
      :label="t('memories', 'Search')"
      :placeholder="t('memories', 'Search location / landmark')"
      :disabled="disabled"
      trailing-button-icon="arrowRight"
      :show-trailing-button="searchBar.length > 0 && !loading"
      @trailing-button-click="search"
      @keypress.enter="search"
    >
      <MagnifyIcon :size="16" />
    </NcTextField>

    <div class="osm-attribution">
      Powered by
      <a href="https://nominatim.openstreetmap.org" target="_blank">Nominatim</a>
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

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcListItem = defineAsyncComponent(() => import('@nextcloud/vue/components/NcListItem'));

import type { IPhoto } from '@typings';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import UndoIcon from 'vue-material-design-icons/UndoVariant.vue';

type NLocation = {
  osm_id: number;
  type?: string;
  icon?: string;
  display_name: string;
  lat: string;
  lon: string;
};

export default defineComponent({
  components: {
    NcActions,
    NcActionButton,
    NcTextField,
    NcListItem,
    MagnifyIcon,
    CloseIcon,
    UndoIcon,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    dirty: false,
    lat: null as number | null,
    lon: null as number | null,
    searchBar: '',
    loading: false,

    options: [] as NLocation[],
  }),

  computed: {
    loc() {
      if (this.lat && this.lon) {
        return `${this.lat.toFixed(6)}, ${this.lon.toFixed(6)}`;
      }
      return this.t('memories', 'No coordinates');
    },
  },

  mounted() {
    this.reset();
  },

  methods: {
    reset() {
      this.dirty = false;
      const photos = this.photos as IPhoto[];

      let lat = 0,
        lon = 0,
        count = 0;
      for (const photo of photos) {
        const exif = photo.imageInfo?.exif;
        if (!exif) {
          continue;
        }

        if (exif.GPSLatitude && exif.GPSLongitude) {
          lat += Number(exif.GPSLatitude);
          lon += Number(exif.GPSLongitude);
          count++;
        }
      }

      if (count > 0) {
        this.lat = lat / count;
        this.lon = lon / count;
      } else {
        this.lat = this.lon = null;
      }
    },

    search() {
      if (this.loading || this.searchBar.length === 0) {
        return;
      }

      // Check if searchbar is already a coordinate
      const coords = this.searchBar.split(',');
      if (coords.length === 2) {
        const lat = Number(coords[0].trim());
        const lon = Number(coords[1].trim());
        if (!isNaN(lat) && !isNaN(lon)) {
          return this.select({
            osm_id: 0,
            display_name: `${lat.toFixed(6)}, ${lon.toFixed(6)}`,
            lat: lat.toFixed(6),
            lon: lon.toFixed(6),
          });
        }
      }

      this.loading = true;
      const q = window.encodeURIComponent(this.searchBar);
      axios
        .get<NLocation[]>(`https://nominatim.openstreetmap.org/search?q=${q}&format=jsonv2`)
        .then((response) => {
          this.loading = false;
          this.options = response.data.filter((x) => x.lat && x.lon && x.display_name);
        })
        .catch((error) => {
          this.loading = false;
          console.error(error);
          showError(this.t('memories', 'Failed to search for location with Nominatim.'));
        });
    },

    clear() {
      this.dirty = true;
      this.lat = 0;
      this.lon = 0;
    },

    select(option: NLocation) {
      this.dirty = true;
      this.lat = Number(option.lat);
      this.lon = Number(option.lon);
      this.options = [];
      this.searchBar = '';
    },

    result() {
      if (!this.dirty || this.lat === null || this.lon === null) return null;

      const lat = this.lat.toFixed(6);
      const lon = this.lon.toFixed(6);

      // Exiftool is actually supposed to pick up the reference from
      // a signed set of coordinates: https://exiftool.org/faq.html#Q14
      // But it doesn't seem to work for some very specific files, so
      // we'll just set it manually to N/S and E/W
      return {
        GPSLatitude: lat,
        GPSLongitude: lon,
        GPSLatitudeRef: this.lat >= 0 ? 'N' : 'S',
        GPSLongitudeRef: this.lon >= 0 ? 'E' : 'W',
        GPSCoordinates: `${lat}, ${lon}`,
      };
    },
  },
});
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
