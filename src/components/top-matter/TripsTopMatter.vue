<template>
  <div class="trips-top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name || viewname }}</div>

    <div class="trip-details" v-if="tripInfo">
      <span v-if="tripInfo.timeframe" class="detail timeframe">
        <TimeframeIcon :size="18" />
        {{ tripInfo.timeframe }}
      </span>
      <span v-if="tripInfo.location" class="detail location">
        <LocationIcon :size="18" />
        {{ tripInfo.location }}
      </span>
    </div>

    <div class="right-actions">
      <NcActions :inline="0">
        <!-- Placeholder for future trip-specific actions -->
      </NcActions>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { translate as t } from '@nextcloud/l10n';

import { NcActions, NcActionButton } from '@nextcloud/vue';
import * as strings from '@services/strings';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import TimeframeIcon from 'vue-material-design-icons/Calendar.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';

import * as dav from '@services/dav';
import type { ITrip } from '@typings';

export default defineComponent({
  name: 'TripsTopMatter',
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
    TimeframeIcon,
    LocationIcon,
  },

  data() {
    return {
      tripInfo: null as ITrip | null,
    };
  },

  computed: {
    name(): string | undefined {
      return this.$route.params.name as string | undefined;
    },

    viewname(): string {
      return t('memories', 'Trips');
    },
  },

  async mounted() {
    // Fetch trip details if we're on a specific trip page
    if (this.name) {
      try {
        const trips = await dav.getTrips();
        const trip = trips.find((t) => String(t.cluster_id) === this.name);
        if (trip) {
          this.tripInfo = trip as ITrip;
        }
      } catch (error) {
        console.error('Failed to load trip details', error);
      }
    }
  },
});
</script>

<style scoped lang="scss">
.trips-top-matter {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  height: 50px;
  margin-bottom: 1rem;

  .name {
    flex: 1;
    margin: 0 0.5rem;
    font-size: 18px;
    font-weight: bold;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .trip-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    margin: 0 12px;

    .detail {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    @media (min-width: 768px) {
      flex-direction: row;
      gap: 12px;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
}
</style>
