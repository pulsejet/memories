<template>
  <NcEmptyContent :name="t('memories', 'Nothing to show here')" :description="emptyViewDescription">
    <template #icon>
      <PeopleIcon v-if="routeIs.People" />
      <ArchiveIcon v-else-if="routeIs.Archive" />
      <TrashIcon v-else-if="routeIs.Trash" />
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

import { useRouteIs } from '@services/route-is';
import { t } from '@services/l10n';
import { config } from '@services/user-config';

import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import TrashIcon from 'vue-material-design-icons/Delete.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';

defineOptions({
  name: 'EmptyContent',
});

const route = useRoute();
const routeIs = useRouteIs();
const emptyViewDescription = computed((): string => {
  if (routeIs.Base) {
    return t('memories', 'Upload some photos and make sure the timeline path is configured');
  }
  if (routeIs.Favorites) {
    return t('memories', 'Mark photos as favorite to find them easily');
  }
  if (routeIs.ThisDay) {
    return t('memories', 'Memories from past years will appear here');
  }
  if (routeIs.Recognize) {
    return t('memories', 'Recognize is still working on your photos');
  }
  if (routeIs.FaceRecognition) {
    return config.facerecognition_enabled
      ? t('memories', 'You will find your friends soon. Please be patient')
      : t('memories', 'Face Recognition is disabled. Enable in settings to find your friends');
  }
  if (routeIs.Videos) {
    return t('memories', 'Your videos will appear here');
  }
  if (routeIs.Panoramas) {
    return t('memories', 'Your panoramas will appear here');
  }
  if (routeIs.Albums) {
    return route.params.name
      ? t('memories', 'Add photos to albums by selecting them on your timeline.')
      : t('memories', 'Create an album to get started');
  }
  if (routeIs.Archive) {
    return t('memories', "Archive photos you don't want to see in your timeline");
  }
  if (routeIs.Trash) {
    return t('memories', 'Deleted photos will appear here.');
  }
  if (routeIs.Tags) {
    return t('memories', 'Tag photos to find them easily');
  }
  if (routeIs.Places) {
    return t('memories', 'Places you have been to will appear here');
  }
  return String();
});
</script>
