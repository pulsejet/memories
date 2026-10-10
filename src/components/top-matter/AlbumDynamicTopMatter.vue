<template>
  <div v-if="isReady && album" class="album-dtm">
    <div v-if="album?.location" class="subtitle">
      <MapMarkerOutlineIcon class="icon" :size="18" />
      <span>{{ album.location }}</span>
    </div>

    <div class="avatars" v-if="(album?.collaborators.length ?? 0) > 1">
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
import { computed, defineAsyncComponent, ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';

import * as utils from '@services/utils/common';
import * as dav from '@services/dav';
import { useRouteState } from '@services/route-state';
import { isAbortError, useAbort } from '@services/utils/abort-vue';

const NcAvatar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAvatar'));

import MapMarkerOutlineIcon from 'vue-material-design-icons/MapMarkerOutline.vue';
import LinkIcon from 'vue-material-design-icons/Link.vue';

defineOptions({
  name: 'AlbumDynamicTopMatter',
});

const emit = defineEmits<{
  load: [];
}>();

const route = useRoute();

const album = ref<dav.IDavAlbum | null>(null);
const abort = useAbort();

useRouteState({
  state: { album },
});

const isAvailable = computed((): boolean => !!utils.uid && !!albumUser.value && !!albumName.value);
const isReady = computed((): boolean => !isAvailable.value || !!album.value);

// Always return false since this is not "real" content.
// This way the empty view of the timeline will show on empty albums.
const isContent = computed((): boolean => false);

const albumUser = computed(() => {
  return route.params.user?.toString() ?? '';
});

const albumName = computed(() => {
  return route.params.name?.toString() ?? '';
});

async function refresh(): Promise<void> {
  // Skip everything if user is not logged in
  if (!utils.uid) return;

  // Skip if we are not on an album (e.g. on the list)
  const user = albumUser.value;
  const name = albumName.value;
  if (!user || !name) return;

  // Create an abort signal.
  const signal = abort.renew();

  // Get DAV album for collaborators
  try {
    const albumData = await dav.getAlbum(user, name, { signal });
    signal.throwIfAborted();
    album.value = albumData;
    emit('load');
  } catch (e) {
    if (isAbortError(e)) return;
    console.warn('Failed to fetch album:', e);
  } finally {
    if (!signal.aborted) {
      album.value ??= {
        collaborators: [],
        location: '',
      };
    }
  }
}

onMounted(refresh);

defineExpose({ isReady, isContent, refresh });
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
