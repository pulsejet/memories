<template>
  <!--
    The outer content wrapper must be static and not change
    since other components might be mounted onto it (e.g. Sidebar)
  -->
  <NcContent
    app-name="memories"
    :class="{
      'has-nav': showNavigation,
    }"
  >
    <!--
      Some routes may desire to skip everything inside and only show their
      own content view. Enlist these routes here.
    -->
    <router-view v-if="routeIs.NxSetup" />

    <!--
      Timline path is not set: short circuit and only show the first start.
      There are some assumptions in the app that timeline path always exists.
      This is not the same as above since FirstStart is not a route.
    -->
    <FirstStart v-else-if="isFirstStart" />

    <!-- Render the actual app when configuration has been loaded -->
    <template v-else-if="!isConfigUnknown">
      <NcAppNavigation v-if="showNavigation" :aria-label="t('memories', 'Navigation')">
        <template #list>
          <NcAppNavigationItem
            v-for="item in navItems"
            :key="item.name"
            :to="{ name: item.name }"
            :name="item.title"
            :active="$route.name === item.name"
            @click="linkClick"
            exact
          >
            <template #icon>
              <component :is="item.icon" :size="20" />
            </template>
          </NcAppNavigationItem>
        </template>

        <template #footer>
          <ul class="app-navigation__settings">
            <NcAppNavigationItem :name="t('memories', 'Settings')" @click="showSettings" href="#ss">
              <template #icon>
                <CogIcon :size="20" />
              </template>
            </NcAppNavigationItem>
          </ul>
        </template>
      </NcAppNavigation>

      <NcAppContent :allowSwipeNavigation="false">
        <div
          :class="{
            outer: true,
            'router-outlet': true,
            'has-nav': showNavigation,
            'has-mobile-header': hasMobileHeader,
            'is-native': native,
          }"
        >
          <router-view />
        </div>

        <MobileHeader v-if="hasMobileHeader" />
        <MobileNav v-if="showNavigation" />
      </NcAppContent>

      <Settings v-model:open="settingsOpen" />

      <Teleport to="body">
        <Viewer />
        <Sidebar />
      </Teleport>
    </template>

    <EditMetadataModal />
    <AddToAlbumModal />
    <NodeShareModal />
    <ShareModal />
    <MoveToFolderModal />
    <FaceMoveModal />
    <AlbumShareModal />
    <UploadModal />
    <SearchModal />
    <ReindexModal />
  </NcContent>
</template>

<script setup lang="ts">
import { computed, markRaw, onBeforeMount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { defineAsyncComponent } from 'vue';

import NcContent from '@nextcloud/vue/components/NcContent';
import NcAppContent from '@nextcloud/vue/components/NcAppContent';
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation';
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem';

import { generateUrl } from '@nextcloud/router';
import { routeIs } from '@services/router';

const FirstStart = defineAsyncComponent(() => import('@components/FirstStart.vue'));
import Settings from '@components/Settings.vue';
import Viewer from '@components/viewer/Viewer.vue';
import Sidebar from '@components/Sidebar.vue';
import MobileNav from '@components/MobileNav.vue';
import MobileHeader from '@components/MobileHeader.vue';

import EditMetadataModal from '@components/modal/EditMetadataModal.vue';
import AddToAlbumModal from '@components/modal/AddToAlbumModal.vue';
import NodeShareModal from '@components/modal/NodeShareModal.vue';
import ShareModal from '@components/modal/ShareModal.vue';
import MoveToFolderModal from '@components/modal/MoveToFolderModal.vue';
import ReindexModal from '@components/modal/ReindexModal.vue';
import FaceMoveModal from '@components/modal/FaceMoveModal.vue';
import AlbumShareModal from '@components/modal/AlbumShareModal.vue';
import UploadModal from '@components/modal/UploadModal.vue';
import SearchModal from '@components/modal/SearchModal.vue';

import * as utils from '@services/utils/common';
import * as nativex from '@native';
import { translate as t } from '@services/l10n';
import { config, hasVersionChanged } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { getPlayableVideoCodecs } from '@services/video/codec';

import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue';
import FolderIcon from 'vue-material-design-icons/Folder.vue';
import Star from 'vue-material-design-icons/Star.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import PeopleIcon from 'vue-material-design-icons/AccountBoxMultiple.vue';
import MarkerIcon from 'vue-material-design-icons/MapMarker.vue';
import TagsIcon from 'vue-material-design-icons/Tag.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';

type NavItem = {
  name: string;
  title: string;
  icon: any;
  if?: any;
};

defineOptions({
  name: 'App',
});

const route = useRoute();
const navItems = ref<NavItem[]>([]);
const settingsOpen = ref(false);

watch(
  () => route.params.token,
  (token?: string | string[]) => {
    syncSharingToken(token?.toString());
  },
  { immediate: true },
);

const native = computed((): boolean => {
  return nativex.has();
});

const recognize = computed((): string | false => {
  if (!config.recognize_enabled) {
    return false;
  }

  if (config.facerecognition_installed) {
    return t('memories', 'People (Recognize)');
  }

  return t('memories', 'People');
});

const facerecognition = computed((): string | false => {
  if (!config.facerecognition_installed) {
    return false;
  }

  if (config.recognize_enabled) {
    return t('memories', 'People (Face Recognition)');
  }

  return t('memories', 'People');
});

const isFirstStart = computed((): boolean => {
  return config.timeline_path === '_empty_' && !routeIs.Public && !route.query.noinit;
});

const isConfigUnknown = computed((): boolean => {
  return config.timeline_path === '_unknown_';
});

const showAlbums = computed((): boolean => {
  return config.albums_enabled;
});

const showNavigation = computed((): boolean => {
  if (routeIs.Public || isFirstStart.value) {
    return false;
  }

  if (native.value) {
    // Only show navigation on "main" tabs
    return routeIs.Base || routeIs.Explore || (routeIs.Albums && !route.params.name);
  }

  return true;
});

const hasMobileHeader = computed((): boolean => {
  return native.value && showNavigation.value && routeIs.Base;
});

// Register navigation items on config change
watch(config, refreshNav);

// Register global functions
_m.modals.showSettings = showSettings;

// Warm codec detection for video URLs
void getPlayableVideoCodecs();

onMounted(() => {
  refreshNav();

  // Store CSS variables modified
  const root = document.documentElement;
  const colorPrimary = getComputedStyle(root).getPropertyValue('--color-primary');
  root.style.setProperty('--color-primary-select-light', `${colorPrimary}40`);
  root.style.setProperty('--media-brand', colorPrimary);

  // Set theme color to default
  // Skip on nxsetup and firststart to avoid flashing white on initial setup.
  if (!routeIs.NxSetup && !isFirstStart.value) {
    nativex.setTheme();
  }

  // Check for native interface
  if (native.value) {
    document.documentElement.classList.add('native');
  }

  // Close navigation by default if init is disabled
  // This is the case for public folder/album shares
  if (route.query.noinit) {
    utils.bus.emit('toggle-navigation', { open: false });
  }
});

onBeforeMount(async () => {
  if ('serviceWorker' in navigator && !nativex.has()) {
    // Use the window load event to keep the page load performant
    window.addEventListener('load', async () => {
      try {
        const url = generateUrl('/apps/memories/static/service-worker.js');
        const registration = await navigator.serviceWorker.register(url, {
          scope: generateUrl('/apps/memories'),
        });
        console.info('SW registered: ', registration);

        // Check for updates
        if (await hasVersionChanged()) {
          await registration.update();
        }
      } catch (error) {
        console.error('SW registration failed: ', error);
      }
    });
  } else {
    console.debug('Service Worker is not enabled on this browser.');
  }
});

function refreshNav() {
  const items = [
    {
      name: 'timeline',
      icon: markRaw(ImageMultiple),
      title: t('memories', 'Timeline'),
    },
    {
      name: 'explore',
      icon: markRaw(SearchIcon),
      title: t('memories', 'Explore'),
    },
    {
      name: 'folders',
      icon: markRaw(FolderIcon),
      title: t('memories', 'Folders'),
    },
    {
      name: 'favorites',
      icon: markRaw(Star),
      title: t('memories', 'Favorites'),
    },
    {
      name: 'albums',
      icon: markRaw(AlbumIcon),
      title: t('memories', 'Albums'),
      if: showAlbums.value,
    },
    {
      name: 'recognize',
      icon: markRaw(PeopleIcon),
      title: recognize.value || '',
      if: recognize.value,
    },
    {
      name: 'facerecognition',
      icon: markRaw(PeopleIcon),
      title: facerecognition.value || '',
      if: facerecognition.value,
    },
    {
      name: 'archive',
      icon: markRaw(ArchiveIcon),
      title: t('memories', 'Archive'),
    },
    {
      name: 'thisday',
      icon: markRaw(CalendarIcon),
      title: t('memories', 'On this day'),
    },
    {
      name: 'places',
      icon: markRaw(MarkerIcon),
      title: t('memories', 'Places'),
      if: config.places_gis > 0,
    },
    {
      name: 'map',
      icon: markRaw(MapIcon),
      title: t('memories', 'Map'),
    },
    {
      name: 'tags',
      icon: markRaw(TagsIcon),
      title: t('memories', 'Tags'),
      if: config.systemtags_enabled,
    },
  ];

  navItems.value = items.filter((item) => item.if === undefined || Boolean(item.if));
}

function linkClick() {
  if (windowDims.width <= 1024) {
    utils.bus.emit('toggle-navigation', { open: false });
  }
}

function showSettings() {
  settingsOpen.value = true;
}

// https://github.com/pulsejet/memories/issues/1634
function syncSharingToken(token?: string) {
  document.querySelector('input#sharingToken')?.remove();
  if (!token) return;

  const el = document.createElement('input');
  el.id = 'sharingToken';
  el.type = 'hidden';
  el.value = token;
  document.body.appendChild(el);
}
</script>

<style scoped lang="scss">
.outer {
  padding: 0 0 0 44px;
  height: 100%;
  width: 100%;

  &.remove-gap,
  &:has(.map-matter) {
    padding: 0; // gap on map left
  }
}

@media (max-width: 768px) {
  .outer {
    padding: 0px;
  }
}

ul.app-navigation__settings {
  height: auto !important;
  overflow: hidden !important;
  padding: var(--app-navigation-padding);
  flex: 0 0 auto;
}
</style>
