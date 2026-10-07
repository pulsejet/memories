<template>
  <div v-if="systemConfig" class="admin-section">
    <h2>{{ $options.title }}</h2>

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
              ? t('memories', 'Database is populated with {n} geometries.', { n: status.gis_count })
              : t('memories', 'Geometry table has not been created.')
          }}
          {{
            status.gis_count > 0 && status.gis_count <= 500000
              ? t('memories', 'Looks like the planet data is incomplete.')
              : String()
          }}
        </NcNoteCard>
        <NcNoteCard
          v-if="typeof systemConfig['memories.gis_type'] !== 'number' || systemConfig['memories.gis_type'] < 0"
          type="warning"
        >
          {{
            t('memories', 'Reverse geocoding has not been configured ({status}).', {
              status: systemConfig['memories.gis_type'],
            })
          }}
        </NcNoteCard>
      </template>

      {{ t('memories', 'Memories supports offline reverse geocoding using OpenStreetMap data on MySQL and Postgres.') }}
      <br />
      {{
        t(
          'memories',
          'You need to download the planet data into your database. This is highly recommended and has low overhead.',
        )
      }}
      <br />
      {{ t('memories', 'If the button below does not work for importing the planet data, use the following command:') }}
      <br />
      <code>occ memories:places-setup</code>
      <br />
      {{ t('memories', 'Note: the geometry data is stored in the memories_planet_geometry table, with no prefix.') }}
    </p>

    <form :action="placesSetupUrl" method="post" @submit.prevent.stop="placesSetup" target="_blank">
      <input name="requesttoken" type="hidden" :value="requestToken" />
      <input name="actiontoken" type="hidden" :value="actionToken" />
      <NcButton nativeType="submit" variant="warning">
        {{ t('memories', 'Download planet database') }}
      </NcButton>
    </form>

    <div style="margin-top: 1.3em">
      <NcTextField
        :label="t('memories', 'Location search endpoint for metadata editor')"
        :label-visible="true"
        placeholder="https://nominatim.openstreetmap.org"
        :model-value="systemConfig['memories.places.search.url']"
        @change="update('memories.places.search.url', $event.target.value.trim())"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

import axios from '@nextcloud/axios';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcButton from '@nextcloud/vue/components/NcButton';
import { API } from '@services/API';

import { t } from '@services/l10n';
import * as utils from '@services/utils/common';

import { useAdminSection } from '../useAdminSection';

defineOptions({
  name: 'Places',
  title: t('memories', 'Reverse Geocoding'),
});

const { status, systemConfig, update } = useAdminSection();

const requestToken = computed(() => (<any>axios.defaults.headers).requesttoken);
const actionToken = computed(() => status.value?.action_token || '');

const gisStatus = computed(() => {
  if (!status.value) return '';

  if (typeof status.value.gis_type !== 'number') {
    return status.value.gis_type;
  }

  if (status.value.gis_type <= 0) {
    return t('memories', 'Geometry support was not detected in your database');
  } else if (status.value.gis_type === 1) {
    return t('memories', 'MySQL-like geometry support was detected ');
  } else if (status.value.gis_type === 2) {
    return t('memories', 'Postgres native geometry support was detected');
  }
});

const gisStatusType = computed(() => {
  return typeof status.value?.gis_type !== 'number' || status.value.gis_type <= 0 ? 'error' : 'success';
});

const placesSetupUrl = computed(() => {
  return API.OCC_PLACES_SETUP();
});

async function placesSetup(event: Event) {
  // construct warning
  const warnSetup = t(
    'memories',
    'Looks like the database is already setup. Are you sure you want to redownload planet data?',
  );
  const warnLong = t('memories', 'You are about to download the planet database. This may take a while.');
  const warnReindex = t('memories', 'This may also cause all photos to be re-indexed!');
  const msg = (status.value?.gis_count ? warnSetup : warnLong) + ' ' + warnReindex;

  // ask the user
  if (
    await utils.confirmDestructive({
      title: t('memories', 'Download planet database'),
      message: msg,
      confirm: t('memories', 'Continue'),
      confirmClasses: 'error',
      cancel: t('memories', 'Cancel'),
    })
  ) {
    // submit the form
    (event.target as HTMLFormElement).submit();
  }
}
</script>
