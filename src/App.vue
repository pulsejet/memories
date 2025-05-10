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
    <router-view v-if="routeIsNxSetup" />

    <!--
      Timline path is not set: short circuit and only show the first start.
      There are some assumptions in the app that timeline path always exists.
      This is not the same as above since FirstStart is not a route.
    -->
    <FirstStart v-else-if="isFirstStart" />

    <!-- Render the actual app when configuration has been loaded -->
    <template v-else-if="!isConfigUnknown">
      <NcAppNavigation v-if="showNavigation">
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
            <component :is="item.icon" slot="icon" :size="20" />
          </NcAppNavigationItem>
        </template>

        <template #footer>
          <ul class="app-navigation__settings">
            <NcAppNavigationItem :name="t('memories', 'Settings')" @click="showSettings" href="#ss">
              <CogIcon slot="icon" :size="20" />
            </NcAppNavigationItem>
          </ul>
        </template>
      </NcAppNavigation>

      <NcAppContent :allowSwipeNavigation="false">
        <div
          :class="{
            outer: true,
            'router-outlet': true,
            'remove-gap': removeNavGap,
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

      <Settings :open.sync="settingsOpen" />

      <Viewer />
      <Sidebar />

      <EditMetadataModal />
      <AddToAlbumModal />
      <NodeShareModal />
      <ShareModal />
      <MoveToFolderModal />
      <FaceMoveModal />
      <AlbumShareModal />
      <UploadModal />
      <SearchModal />
    </template>
  </NcContent>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcContent from '@nextcloud/vue/dist/Components/NcContent.js';
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js';
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js';
const NcAppNavigationItem = () => import('@nextcloud/vue/dist/Components/NcAppNavigationItem.js');

import { generateUrl } from '@nextcloud/router';

import UserConfig from '@mixins/UserConfig';

import Timeline from '@components/Timeline.vue';
import Settings from '@components/Settings.vue';
import FirstStart from '@components/FirstStart.vue';
import Viewer from '@components/viewer/Viewer.vue';
import Sidebar from '@components/Sidebar.vue';
import MobileNav from '@components/MobileNav.vue';
import MobileHeader from '@components/MobileHeader.vue';

import EditMetadataModal from '@components/modal/EditMetadataModal.vue';
import AddToAlbumModal from '@components/modal/AddToAlbumModal.vue';
import NodeShareModal from '@components/modal/NodeShareModal.vue';
import ShareModal from '@components/modal/ShareModal.vue';
import MoveToFolderModal from '@components/modal/MoveToFolderModal.vue';
import FaceMoveModal from '@components/modal/FaceMoveModal.vue';
import AlbumShareModal from '@components/modal/AlbumShareModal.vue';
import UploadModal from '@components/modal/UploadModal.vue';
import SearchModal from '@components/modal/SearchModal.vue';

import * as utils from '@services/utils';
import * as nativex from '@native';
import { translate as t } from '@services/l10n';
import staticConfig from '@services/static-config';

import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue';
import FolderIcon from 'vue-material-design-icons/Folder.vue';
import Star from 'vue-material-design-icons/Star.vue';
import Video from 'vue-material-design-icons/PlayCircle.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import PeopleIcon from 'vue-material-design-icons/AccountBoxMultiple.vue';
import MarkerIcon from 'vue-material-design-icons/MapMarker.vue';
import TagsIcon from 'vue-material-design-icons/Tag.vue';
import MapIcon from 'vue-material-design-icons/Map.vue';
import TripIcon from 'vue-material-design-icons/BagSuitcase.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';
import VideoIcon from 'vue-material-design-icons/Video.vue';

type NavItem = {
  name: string;
  title: string;
  icon: any;
  if?: any;
};

export default defineComponent({
  name: 'App',
  components: {
    NcContent,
    NcAppContent,
    NcAppNavigation,
    NcAppNavigationItem,

    Timeline,
    Settings,
    FirstStart,
    Viewer,
    Sidebar,
    MobileNav,
    MobileHeader,
    SearchModal,

    EditMetadataModal,
    AddToAlbumModal,
    NodeShareModal,
    ShareModal,
    MoveToFolderModal,
    FaceMoveModal,
    AlbumShareModal,
    UploadModal,

    ImageMultiple,
    FolderIcon,
    Star,
    Video,
    AlbumIcon,
    ArchiveIcon,
    CalendarIcon,
    PeopleIcon,
    MarkerIcon,
    TagsIcon,
    MapIcon,
    TripIcon,
    CogIcon,
    SearchIcon,
    VideoIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    navItems: [] as NavItem[],
    settingsOpen: false,
  }),

  computed: {
    native(): boolean {
      return nativex.has();
    },

    recognize(): string | false {
      if (!this.config.recognize_enabled) {
        return false;
      }

      if (this.config.facerecognition_installed) {
        return t('memories', 'People (Recognize)');
      }

      return t('memories', 'People');
    },

    facerecognition(): string | false {
      if (!this.config.facerecognition_installed) {
        return false;
      }

      if (this.config.recognize_enabled) {
        return t('memories', 'People (Face Recognition)');
      }

      return t('memories', 'People');
    },

    isFirstStart(): boolean {
      return this.config.timeline_path === '_empty_' && !this.routeIsPublic && !this.$route.query.noinit;
    },

    isConfigUnknown(): boolean {
      return this.config.timeline_path === '_unknown_';
    },

    showAlbums(): boolean {
      return this.config.albums_enabled;
    },

    showNavigation(): boolean {
      if (this.routeIsPublic || this.isFirstStart) {
        return false;
      }

      if (this.native) {
        // Only show navigation on "main" tabs
        return this.routeIsBase || this.routeIsExplore || (this.routeIsAlbums && !this.$route.params.name);
      }

      return true;
    },

    hasMobileHeader(): boolean {
      return this.native && this.showNavigation && this.routeIsBase;
    },

    removeNavGap(): boolean {
      return this.routeIsMap;
    },
  },

  created() {
    // No real need to unbind these, as the app is never destroyed
    const onResize = () => {
      _m.window.innerWidth = window.innerWidth;
      _m.window.innerHeight = window.innerHeight;
      utils.bus.emit('memories:window:resize', null);
    };
    window.addEventListener('resize', () => {
      utils.setRenewingTimeout(this, 'resizeTimer', onResize, 100);
    });

    // Register navigation items on config change
    utils.bus.on('memories:user-config-changed', this.refreshNav);

    // Register global functions
    _m.modals.showSettings = this.showSettings;
  },

  mounted() {
    this.refreshNav();

    // Store CSS variables modified
    const root = document.documentElement;
    const colorPrimary = getComputedStyle(root).getPropertyValue('--color-primary');
    root.style.setProperty('--color-primary-select-light', `${colorPrimary}40`);
    root.style.setProperty('--plyr-color-main', colorPrimary);

    // Set theme color to default
    nativex.setTheme();

    // Check for native interface
    if (this.native) {
      document.documentElement.classList.add('native');
    }

    // Close navigation by default if init is disabled
    // This is the case for public folder/album shares
    if (this.$route.query.noinit) {
      utils.bus.emit('toggle-navigation', { open: false });
    }
  },

  async beforeMount() {
    if ('serviceWorker' in navigator) {
      // Use the window load event to keep the page load performant
      window.addEventListener('load', async () => {
        try {
          const url = generateUrl('/apps/memories/static/service-worker.js');
          const registration = await navigator.serviceWorker.register(url, {
            scope: generateUrl('/apps/memories'),
          });
          console.info('SW registered: ', registration);

          // Check for updates
          if (await staticConfig.versionChanged()) {
            await registration.update();
          }
        } catch (error) {
          console.error('SW registration failed: ', error);
        }
      });
    } else {
      console.debug('Service Worker is not enabled on this browser.');
    }
  },

  methods: {
    refreshNav() {
      const navItems = [
        {
          name: 'timeline',
          icon: ImageMultiple,
          title: t('memories', 'Timeline'),
        },
        {
          name: 'explore',
          icon: SearchIcon,
          title: t('memories', 'Explore'),
        },
        {
          name: 'folders',
          icon: FolderIcon,
          title: t('memories', 'Folders'),
        },
        {
          name: 'favorites',
          icon: Star,
          title: t('memories', 'Favorites'),
        },
        {
          name: 'albums',
          icon: AlbumIcon,
          title: t('memories', 'Albums'),
          if: this.showAlbums,
        },
        {
          name: 'recognize',
          icon: PeopleIcon,
          title: this.recognize || '',
          if: this.recognize,
        },
        {
          name: 'facerecognition',
          icon: PeopleIcon,
          title: this.facerecognition || '',
          if: this.facerecognition,
        },
        {
          name: 'archive',
          icon: ArchiveIcon,
          title: t('memories', 'Archive'),
        },
        {
          name: 'thisday',
          icon: CalendarIcon,
          title: t('memories', 'On this day'),
        },
        {
          name: 'places',
          icon: MarkerIcon,
          title: t('memories', 'Places'),
          if: this.config.places_gis > 0,
        },
        {
          name: 'trips',
          icon: TripIcon,
          title: t('memories', 'Trips'),
          if: this.config.enable_trips,
        },
        {
          name: 'trip-videos',
          icon: VideoIcon,
          title: t('memories', 'Trip Videos'),
          if: this.config.enable_trips,
        },
        {
          name: 'map',
          icon: MapIcon,
          title: t('memories', 'Map'),
        },
        {
          name: 'tags',
          icon: TagsIcon,
          title: t('memories', 'Tags'),
          if: this.config.systemtags_enabled,
        },
      ];

      this.navItems = navItems.filter((item) => typeof item.if === 'undefined' || Boolean(item.if));
    },

    linkClick() {
      if (_m.window.innerWidth <= 1024) {
        utils.bus.emit('toggle-navigation', { open: false });
      }
    },

    showSettings() {
      this.settingsOpen = true;
    },
  },
});
</script>

<style scoped lang="scss">
.outer {
  padding: 0 0 0 44px;
  height: 100%;
  width: 100%;

  &.remove-gap {
    // Fill up the whole space, e.g. on map
    padding: 0;
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
  padding-top: 0 !important;
  flex: 0 0 auto;
}
</style>
