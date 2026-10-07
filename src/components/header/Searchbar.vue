<template>
  <div ref="outer" class="memories-searchbar">
    <NcPopover :shown="shown" :no-focus-trap="true" @after-hide="pHidden = true">
      <template #trigger="{ attrs }">
        <div v-bind="attrs">
          <NcTextField
            ref="textField"
            class="text-field"
            v-model="prompt"
            autocomplete="off"
            :label-outside="true"
            :label="t('memories', 'Search your photos …')"
            :placeholder="t('memories', 'Search your photos …')"
            @keydown.enter="openSearch"
          >
            <template #icon>
              <MagnifyIcon :size="16" />
            </template>
          </NcTextField>
        </div>
      </template>

      <div class="searchbar-results" v-if="!isLensLive">
        <div v-if="showLensEntry" class="cluster" @click="openSearch">
          <div class="icon">
            <MagnifyIcon :size="22" />
          </div>
          {{ lensEntryText }}
        </div>

        <div class="empty" v-if="prompt.length === 0">
          {{ t('memories', 'Start typing to find photos and albums') }}
        </div>
        <div class="empty" v-else-if="!clusters && clustersLoad">
          <XLoadingIcon class="fill-block" />
        </div>
        <div class="empty" v-else-if="clustersResult.length === 0">
          {{ t('memories', 'No results found') }}
        </div>

        <template v-for="cluster of clustersResult">
          <router-link class="cluster" :to="clusterTarget(cluster)" @click.native="select()">
            <div class="icon">
              <AlbumIcon v-if="clusterIs.album(cluster)" :size="22" />
              <LocationIcon v-else-if="clusterIs.place(cluster)" :size="22" />
              <TagIcon v-else-if="clusterIs.tag(cluster)" :size="22" />
              <XImg v-else-if="clusterIs.face(cluster)" :src="clusterPreview(cluster)" class="preview-image" />
              <MagnifyIcon v-else :size="22" />
            </div>

            {{ cluster.display_name ?? cluster.name }}
          </router-link>
        </template>
      </div>
    </NcPopover>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, useTemplateRef, defineAsyncComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcPopover = defineAsyncComponent(() => import('@nextcloud/vue/components/NcPopover'));

import { config } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { routeIs } from '@services/router';
import { t } from '@services/l10n';

import * as dav from '@services/dav';
import * as lens from '@services/lens';
import { RenewingTimeout } from '@services/utils/renewing-timeout';

import Fuse from 'fuse.js';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';
import XImg from '@components/frame/XImg.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import type { ICluster } from '@typings';

const props = withDefaults(
  defineProps<{
    autoFocus?: boolean;
  }>(),
  {
    autoFocus: false,
  },
);

const emit = defineEmits<{
  (e: 'select'): void;
}>();

const route = useRoute();
const router = useRouter();
const textFieldRef = useTemplateRef<any>('textField');

const prompt = ref(String());

// Popover can be hidden by clicking outside and
// so subsequent changes to prompt do not trigger
// it to show again. This flag is used to force it.
const pHidden = ref(false);

// Pending live lens navigation (debounced)
const lensTimer = new RenewingTimeout();

const clusters = ref<ICluster[] | null>(null);
const clustersLoad = ref(false);
const clusterIs = dav.clusterIs;
const clusterPreview = dav.getClusterPreview;
const clusterTarget = dav.getClusterLinkTarget;

onMounted(() => {
  syncPromptFromRoute();
  setTimeout(() => {
    syncPromptFromRoute();
    if (props.autoFocus) {
      textFieldRef.value?.focus();
    }
  }, 100); // wait for opacity transition
});

const shown = computed(() => {
  // Live search mode navigates directly; no popover needed
  return !pHidden.value && !!prompt.value.length;
});

const clustersResult = computed((): ICluster[] => {
  if (!prompt.value) return [];
  return clustersFuse.value.search(prompt.value, { limit: 6 }).map((r) => r.item);
});

const clustersFuse = computed(() => {
  return new Fuse(clusters.value ?? [], { keys: ['name', 'display_name'], threshold: 0.3 });
});

/** Lens backend available (daemon URL configured) */
const lensEnabled = computed((): boolean => {
  return !!config.lens_enabled;
});

/** Live lens search hijacks typing only on desktop timeline/search views */
const isLensLive = computed((): boolean => {
  return lensEnabled.value && (routeIs.Base || routeIs.Search) && !windowDims.isMobile;
});

/** Explicit lens entry for anywhere live search does not apply */
const showLensEntry = computed((): boolean => {
  return !!prompt.value && lensEnabled.value && !isLensLive.value;
});

const lensEntryText = computed((): string => {
  return t('memories', 'Find photos matching “{query}”', { query: prompt.value });
});

watch(prompt, (val: string) => {
  pHidden.value = false;
  if (val) {
    load(); // load clusters
  }

  // Queue lens search if route changed.
  if (lens.routeQueryText(route.query.q) !== val) {
    queueLensSearch();
  }
});

watch(
  () => route.query.q,
  () => {
    syncPromptFromRoute();
  },
);

function select() {
  prompt.value = String();
  emit('select');
}

async function load() {
  // Load all clusters that we can search in
  if (!clustersLoad.value) {
    clustersLoad.value = true;

    const noop = new Promise<ICluster[]>((r) => r([]));

    const results = await Promise.allSettled([
      config.recognize_enabled ? dav.getFaceList('recognize') : noop,
      config.facerecognition_enabled ? dav.getFaceList('facerecognition') : noop,
      config.places_gis > 0 ? dav.getPlaces({ covers: 0 }) : noop,
      config.systemtags_enabled ? dav.getTags() : noop,
      config.albums_enabled ? dav.getAlbums() : noop,
    ]);

    // Ignore all errors and flatten
    clusters.value = results
      .flatMap((r) => (r.status === 'fulfilled' ? r.value : []))
      .filter((c) => !!(c.name || c.display_name));
  }
}

/** Mirror ?q= into the box when on the search view (e.g. direct open). */
function syncPromptFromRoute() {
  const query = routeIs.Search ? lens.routeQueryText(route.query.q) : String();
  if (query !== prompt.value) prompt.value = query;
}

/** Open the search view for the current prompt */
function openSearch() {
  const q = prompt.value.trim();
  if (!q || !lensEnabled.value || isLensLive.value) return;
  lensTimer.clear();
  router.push({ name: 'search', query: { q } });
  prompt.value = q;
  pHidden.value = true;
  emit('select');
}

/** Live lens search on desktop timeline/search views */
function queueLensSearch() {
  if (!isLensLive.value) return;
  lensTimer.set(routeToLens, 500);
}

/** Run the pending live lens navigation */
function routeToLens() {
  if (!lensEnabled.value) return;
  if (!prompt.value) {
    if (!routeIs.Base) {
      router.replace({ name: 'timeline' });
    }
  } else {
    router.replace({
      name: 'search',
      query: {
        ...route.query,
        q: prompt.value,
      },
    });
  }
}
</script>

<style lang="scss" scoped>
.memories-searchbar .text-field {
  width: 220px;
  max-width: calc(100% - 20px);
  margin: 0 auto;

  @media (max-width: 768px) {
    width: 400px;
  }

  header & {
    --searchbar-color: var(--color-background-plain-text, var(--color-primary-text));
  }

  #mobile-header &,
  .explore-outer & {
    --searchbar-color: var(--color-main-text);
  }

  // Size styles for header only
  header &,
  #mobile-header & {
    max-width: 100%;
    // header is 50px; 5px gap on each side
    margin: 5px 0 !important;
    --default-clickable-area: 40px;
  }

  // Styling for flat input
  header &,
  #mobile-header &,
  .explore-outer & {
    // Remove padding from text bar
    --border-width-input-focused: 0px;

    :deep(input[type='text']) {
      border: none !important;
      background-color: color-mix(in srgb, var(--searchbar-color) 12%, transparent);
      backdrop-filter: blur(2px);

      // input[type='text'] has an ugly border on hover and focus
      // with !important, we need to override it with more specificity
      box-shadow: none !important;

      // Prevent jumping text on hover / focus
      --input-border-width-offset: 0px;
    }

    :deep(*),
    :deep(input[type='text']::placeholder) {
      color: var(--searchbar-color);
    }
  }

  .explore-outer & {
    width: 100%;
  }
}

.memories-searchbar.full-width .text-field {
  width: 100%;
}

.searchbar-results {
  padding: 10px 0;
  width: 400px;
  max-width: calc(100vw - 20px);

  .empty {
    text-align: center;
    padding: 8px 14px;

    &:has(.loading-icon) {
      margin: 12px;
    }
  }

  .cluster {
    display: block;
    padding: 8px 14px;
    cursor: pointer;
    text-overflow: ellipsis;
    white-space: nowrap;
    overflow: hidden;

    &:hover {
      background-color: var(--color-background-hover);
    }

    .icon {
      display: inline-block;
      transform: translateY(2px);
      width: 28px;

      > .material-design-icon {
        display: inline-block;
        vertical-align: middle;
      }

      .preview-image {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        vertical-align: top;
      }
    }
  }
}
</style>
