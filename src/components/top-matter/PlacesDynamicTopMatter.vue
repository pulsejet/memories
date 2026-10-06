<template>
  <div class="places-dtm">
    <div class="place-btn" v-for="place of places" :key="place.cluster_id">
      <NcButton class="place" :to="routeTo(place)">{{ place.name }}</NcButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';

import axios from '@nextcloud/axios';
import NcButton from '@nextcloud/vue/components/NcButton';

import { API } from '@services/API';
import { useRouteIsPlacesUnassigned } from '@services/route-checker';
import * as utils from '@services/utils';

import type { ICluster } from '@typings';

defineOptions({
  name: 'PlacesDynamicTopMatter',
});

const route = useRoute();
const routeIsPlacesUnassigned = useRouteIsPlacesUnassigned();

const places = ref<ICluster[]>([]);

const placeId = computed((): number => {
  return Number(utils.routeParamToString(route.params.name).split('-')[0]) || -1;
});

async function refresh(): Promise<boolean> {
  // Clear subplaces
  places.value = [];

  // Skip if unidentified location view
  if (routeIsPlacesUnassigned.value) return false;

  // Get ID of place from URL
  const placeIdVal = placeId.value;
  const url = API.Q(API.PLACE_LIST(), { inside: placeIdVal });

  // Make API call to get subplaces
  try {
    const data = (await axios.get<ICluster[]>(url)).data;
    if (placeIdVal !== placeId.value) {
      return false;
    }
    places.value = data;
  } catch (e) {
    console.error(e);
    return false;
  }

  return places.value.length > 0;
}

function routeTo(place: ICluster) {
  return {
    name: _m.routes.Places.name,
    params: {
      name: place.cluster_id + '-' + place.name,
    },
  };
}

defineExpose({ refresh });
</script>

<style lang="scss" scoped>
.places-dtm {
  margin: 0 0.3em;

  div.place-btn {
    display: inline-block;

    > a,
    > button {
      font-size: 0.85em;
      min-height: unset;
      margin: 3px 2px;
      padding: 0px 6px;
    }
  }

  @media (min-width: 769px) {
    margin-right: 44px;
  }
}
</style>
