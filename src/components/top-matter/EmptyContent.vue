<template>
  <NcEmptyContent :name="t('memories', 'Nothing to show here')" :description="emptyViewDescription">
    <template #icon>
      <PeopleIcon v-if="routeIs.People" />
      <ArchiveIcon v-else-if="routeIs.Archive" />
      <AlbumIcon v-else-if="routeIs.Albums" />
      <MapIcon v-else-if="routeIs.Map" />
      <ImageMultipleIcon v-else />
    </template>
  </NcEmptyContent>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';

import { routeIs } from '@services/router';
import { t } from '@services/l10n';
import * as strings from '@services/strings';

import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';

defineOptions({
  name: 'EmptyContent',
});

const route = useRoute();
const emptyViewDescription = computed((): string => {
  return strings.emptyDescription(route.name?.toString() ?? '');
});
</script>
