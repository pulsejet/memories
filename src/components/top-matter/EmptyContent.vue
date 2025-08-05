<template>
  <NcEmptyContent :name="t('memories', 'Nothing to show here')" :description="emptyViewDescription">
    <template #icon>
      <PeopleIcon v-if="routeIsPeople" />
      <ArchiveIcon v-else-if="routeIsArchive" />
      <AlbumIcon v-else-if="routeIsAlbums" />
      <MapIcon v-else-if="routeIsMap" />
      <ImageMultipleIcon v-else />
    </template>
  </NcEmptyContent>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';

import * as strings from '@services/strings';

import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';

export default defineComponent({
  name: 'EmptyContent',

  components: {
    NcEmptyContent,

    PeopleIcon,
    ArchiveIcon,
    ImageMultipleIcon,
    AlbumIcon,
    MapIcon,
  },

  computed: {
    emptyViewDescription(): string {
      return strings.emptyDescription(String(this.$route.name));
    },
  },
});
</script>
