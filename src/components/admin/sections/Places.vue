<template>
  <div class="admin-section">
    <h2>{{ t('memories', 'Reverse Geocoding') }}</h2>

    <p>
      <template v-if="status">
        <NcNoteCard :type="gisStatusType">
          {{ gisStatus }}
        </NcNoteCard>
        <NcNoteCard
          v-if="typeof status.gis_count === 'number'"
          :type="status.gis_count > 500000 ? 'success' : 'warning'"
        >
          {{
            status.gis_count > 0
              ? t('memories', 'Database is populated with {n} geometries.', {
                  n: status.gis_count,
                })
              : t('memories', 'Geometry table has not been created.')
          }}
          {{
            status.gis_count > 0 && status.gis_count <= 500000
              ? t('memories', 'Looks like the planet data is incomplete.')
              : ''
          }}
        </NcNoteCard>
        <NcNoteCard
          v-if="typeof config['memories.gis_type'] !== 'number' || config['memories.gis_type'] < 0"
          type="warning"
        >
          {{
            t('memories', 'Reverse geocoding has not been configured ({status}).', {
              status: config['memories.gis_type'],
            })
          }}
        </NcNoteCard>
      </template>

      {{
        t(
          'memories',
          'Memories supports offline reverse geocoding using the OpenStreetMaps data on MySQL and Postgres.'
        )
      }}
      <br />
      {{
        t(
          'memories',
          'You need to download the planet data into your database. This is highly recommended and has low overhead.'
        )
      }}
      <br />
      {{ t('memories', 'If the button below does not work for importing the planet data, use the following command:') }}
      <br />
      <code>occ memories:places-setup</code>
      <br />
      {{ t('memories', 'Note: the geometry data is stored in the memories_planet_geometry table, with no prefix.') }}
    </p>

    <form :action="placesSetupUrl" method="post" @submit="placesSetup" target="_blank">
      <input name="requesttoken" type="hidden" :value="requestToken" />
      <input name="actiontoken" type="hidden" :value="actionToken" />
      <NcButton nativeType="submit" type="warning">
        {{ t('memories', 'Download planet database') }}
      </NcButton>
    </form>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { API } from '../../../services/API';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Places',
  mixins: [AdminMixin],

  computed: {
    gisStatus() {
      if (!this.status) return '';

      if (typeof this.status.gis_type !== 'number') {
        return this.status.gis_type;
      }

      if (this.status.gis_type <= 0) {
        return this.t('memories', 'Geometry support was not detected in your database');
      } else if (this.status.gis_type === 1) {
        return this.t('memories', 'MySQL-like geometry support was detected ');
      } else if (this.status.gis_type === 2) {
        return this.t('memories', 'Postgres native geometry support was detected');
      }
    },

    gisStatusType() {
      return typeof this.status?.gis_type !== 'number' || this.status.gis_type <= 0 ? 'error' : 'success';
    },

    placesSetupUrl() {
      return API.OCC_PLACES_SETUP();
    },
  },

  methods: {
    placesSetup(event: Event) {
      const warnSetup = this.t(
        'memories',
        'Looks like the database is already setup. Are you sure you want to redownload planet data?'
      );
      const warnLong = this.t('memories', 'You are about to download the planet database. This may take a while.');
      const warnReindex = this.t('memories', 'This may also cause all photos to be re-indexed!');
      const msg = (this.status?.gis_count ? warnSetup : warnLong) + ' ' + warnReindex;
      if (!confirm(msg)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
    },
  },
});
</script>
