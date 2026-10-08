<template>
  <div class="places-top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name || viewname }}</div>

    <div class="right-actions">
      <NcActions :inline="0">
        <!-- root view (not cluster or unassigned) -->
        <template v-if="!name && !routeIs.PlacesUnassigned">
          <NcActionButton
            :aria-label="t('memories', 'Files without location')"
            @click="openUnassigned"
            close-after-click
          >
            {{ t('memories', 'Files without location') }}
            <template #icon> <UnassignedIcon :size="20" /> </template>
          </NcActionButton>
        </template>
      </NcActions>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import * as strings from '@services/strings';
import * as utils from '@services/utils/common';
import { constants } from '@services/constants';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import UnassignedIcon from 'vue-material-design-icons/MapMarkerOff.vue';

defineOptions({
  name: 'PlacesTopMatter',
});

const route = useRoute();
const router = useRouter();
const viewname = computed((): string => {
  return strings.viewName(route.name?.toString() ?? '');
});

const name = computed((): string | null => {
  if (routeIs.PlacesUnassigned) {
    return t('memories', 'Unidentified location');
  }

  return utils.routeParamToString(route.params.name).split('-').slice(1).join('-');
});

function back() {
  router.go(-1);
}

function openUnassigned() {
  router.push({
    name: _m.routes.Places.name,
    params: {
      name: constants.PLACES_NULL,
    },
  });
}
</script>
