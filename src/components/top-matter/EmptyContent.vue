<template>
  <NcEmptyContent :name="t('memories', 'Nothing to show here')" :description="emptyViewDescription">
    <template #icon>
      <PeopleIcon v-if="routeIsPeople" />
      <ArchiveIcon v-else-if="routeIsArchive" />
      <AlbumIcon v-else-if="routeIsAlbums" />
      <MapIcon v-else-if="routeIsMap" />
      <ImageMultipleIcon v-else />
    </template>
    <template #action>
      <NcButton variant="primary" @click="resetFilters" v-if="hasFilters">
        {{ t('memories', 'Reset filters') }}
      </NcButton>
    </template>
  </NcEmptyContent>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

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
    NcButton,
    PeopleIcon,
    ArchiveIcon,
    ImageMultipleIcon,
    AlbumIcon,
    MapIcon,
  },

  emits: ['reset-filters'],

  props: {
    hasFilters: {
      type: Boolean,
      default: false,
    },
  },

  computed: {
    emptyViewDescription(): string {
      return strings.emptyDescription(this.$route.name!);
    },

  },

  methods: {
    resetFilters() {
      this.$emit('reset-filters');
    },
  },
});
</script>
