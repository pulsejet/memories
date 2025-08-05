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
        <template v-if="!name && !routeIsPlacesUnassigned">
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

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import * as strings from '@services/strings';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import UnassignedIcon from 'vue-material-design-icons/MapMarkerOff.vue';

export default defineComponent({
  name: 'PlacesTopMatter',
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
    UnassignedIcon,
  },

  computed: {
    viewname(): string {
      return strings.viewName(String(this.$route.name!));
    },

    name(): string | null {
      if (this.routeIsPlacesUnassigned) {
        return this.t('memories', 'Unidentified location');
      }

      const name = this.$route.params.name;
      return typeof name === 'string' ? name.split('-').slice(1).join('-') : null;
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    openUnassigned() {
      this.$router.push({
        name: _m.routes.Places.name,
        params: {
          name: this.c.PLACES_NULL,
        },
      });
    },
  },
});
</script>
