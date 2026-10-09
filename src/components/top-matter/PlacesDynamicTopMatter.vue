<template>
  <div class="places-dtm" v-if="isReady && isContent && places">
    <div class="place-btn" v-for="place of places" :key="place.cluster_id">
      <NcButton class="place" :to="routeTo(place)">{{ place.name }}</NcButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, computed, ref } from 'vue';
import { useRoute } from 'vue-router';

import axios from '@nextcloud/axios';
import NcButton from '@nextcloud/vue/components/NcButton';

import { API } from '@services/API';
import { routeIs } from '@services/router';
import { useRouteState } from '@services/route-state';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as utils from '@services/utils/common';

import type { ICluster } from '@typings';

defineOptions({
  name: 'PlacesDynamicTopMatter',
});

const emit = defineEmits<{
  load: [];
}>();

const route = useRoute();

const places = ref<ICluster[] | null>(null);
const abort = useAbort();

useRouteState({
  state: { places },
});

const isAvailable = computed((): boolean => !routeIs.PlacesUnassigned);
const isReady = computed((): boolean => !isAvailable.value || places.value !== null);
const isContent = computed((): boolean => !!places.value?.length);

const placeId = computed((): number => {
  return Number(utils.routeParamToString(route.params.name).split('-')[0]) || -1;
});

async function refresh(): Promise<void> {
  // Skip if unidentified location view
  if (!isAvailable.value) return;

  // Get ID of place from URL
  const placeIdVal = placeId.value;
  const url = API.Q(API.PLACE_LIST(), { inside: placeIdVal });
  const signal = abort.renew();

  // Make API call to get subplaces
  try {
    const data = (await axios.get<ICluster[]>(url, { signal })).data;
    signal.throwIfAborted();
    places.value = data;
    emit('load');
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
  } finally {
    if (!signal.aborted) {
      places.value ??= [];
    }
  }
}

function routeTo(place: ICluster) {
  return {
    name: _m.routes.Places.name,
    params: {
      name: place.cluster_id + '-' + place.name,
    },
  };
}

onMounted(refresh);

defineExpose({ isReady, isContent, refresh });
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
