<template>
  <div class="places-dtm">
    <NcButton class="place" :key="place.cluster_id" v-for="place of places" :to="route(place)">
      {{ place.name }}
    </NcButton>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import NcButton from '@nextcloud/vue/dist/Components/NcButton';

import { API } from '../../services/API';

import type { ICluster } from '../../types';

export default defineComponent({
  name: 'PlacesDynamicTopMatter',

  data: () => ({
    places: [] as ICluster[],
  }),

  components: {
    NcButton,
  },

  methods: {
    async refresh(): Promise<boolean> {
      // Clear folders
      this.places = [];

      // Get ID of place from URL
      const placeId = Number(this.$route.params.name?.split('-')[0]) || -1;
      const url = API.Q(API.PLACE_LIST(), { inside: placeId });

      // Make API call to get subfolders
      try {
        this.places = (await axios.get<ICluster[]>(url)).data;
      } catch (e) {
        console.error(e);
        return false;
      }

      return this.places.length > 0;
    },

    route(place: ICluster) {
      return {
        name: 'places',
        params: {
          name: place.cluster_id + '-' + place.name,
        },
      };
    },
  },
});
</script>

<style lang="scss" scoped>
.places-dtm {
  margin: 0 0.3em;

  button.place {
    font-size: 0.85em;
    min-height: unset;
    display: inline-block;
    margin: 3px 2px;
    padding: 1px 6px;
  }

  @media (min-width: 769px) {
    margin-right: 44px;
  }
}
</style>
