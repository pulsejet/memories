<template>
  <div class="album-dtm">
    <div v-if="album?.location" class="subtitle">
      <MapMarkerOutlineIcon class="icon" :size="18" />
      <span>{{ album.location }}</span>
    </div>

    <div class="avatars" v-if="album && (album?.collaborators.length ?? 0 > 1)">
      <!-- Show own user only if we have other collaborators -->
      <NcAvatar :user="utils.uid!" :showUserStatus="false" />

      <!-- Other collaborators -->
      <template v-for="c of album.collaborators">
        <!-- Links -->
        <NcAvatar v-if="c.type === 3" :isNoUser="true">
          <template #icon>
            <LinkIcon :size="20" />
          </template>
        </NcAvatar>

        <!-- Users and groups -->
        <NcAvatar v-else :key="c.id" :user="c.id" :showUserStatus="false" />
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue';
import { useRoute } from 'vue-router';

import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

const NcAvatar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAvatar'));

import MapMarkerOutlineIcon from 'vue-material-design-icons/MapMarkerOutline.vue';
import LinkIcon from 'vue-material-design-icons/Link.vue';

defineOptions({
  name: 'AlbumDynamicTopMatter',
});

const route = useRoute();

const album = ref<dav.IDavAlbum | null>(null);

const albumUser = computed(() => {
  return route.params.user?.toString() ?? '';
});

const albumName = computed(() => {
  return route.params.name?.toString() ?? '';
});

async function refresh(): Promise<boolean> {
  // Skip everything if user is not logged in
  if (!utils.uid) return false;

  // Skip if we are not on an album (e.g. on the list)
  const user = albumUser.value;
  const name = albumName.value;
  if (!user || !name) return false;

  // Get DAV album for collaborators
  try {
    const albumData = await dav.getAlbum(user, name);
    if (user !== albumUser.value || name !== albumName.value) {
      return false;
    }
    album.value = albumData;
  } catch (e) {
    console.warn('Failed to fetch album:', e);
  }

  // The album header is metadata, not standalone content,
  // so always return false. If true, an empty album would
  // suppress the timeline empty view.
  return false;
}

defineExpose({ refresh });
</script>

<style lang="scss" scoped>
.album-dtm {
  > .subtitle {
    font-size: 1.1em;
    line-height: 1.2em;
    margin-top: 0.5em;
    color: var(--color-text-maxcontrast);
    display: flex;
    padding-left: 10px;

    .icon {
      margin-right: 5px;
    }
  }

  > .avatars {
    display: flex;
    align-items: center;
    gap: 2px;
    line-height: 1.2em;
    margin-top: 0.5em;
    padding-left: 10px;

    :deep(.avatardiv) {
      display: flex;
      align-items: center;
      justify-content: center;
    }
  }
}
</style>
