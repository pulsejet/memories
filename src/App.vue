<template>
  <FirstStart v-if="isFirstStart" />

  <NcContent
    app-name="memories"
    v-else
    :class="{
      'remove-gap': removeOuterGap,
    }"
  >
    <NcAppNavigation v-if="showNavigation" ref="nav">
      <template id="app-memories-navigation" #list>
        <NcAppNavigationItem
          v-for="item in navItems"
          :key="item.name"
          :to="{ name: item.name }"
          :title="item.title"
          @click="linkClick"
          exact
        >
          <component :is="item.icon" slot="icon" :size="20" />
        </NcAppNavigationItem>
      </template>

      <template #footer>
        <NcAppNavigationSettings :title="t('memories', 'Settings')">
          <Settings />
        </NcAppNavigationSettings>
      </template>
    </NcAppNavigation>

    <NcAppContent>
      <div class="outer">
        <router-view />
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script lang="ts">
import Vue, { defineComponent } from "vue";

import NcContent from "@nextcloud/vue/dist/Components/NcContent";
import NcAppContent from "@nextcloud/vue/dist/Components/NcAppContent";
import NcAppNavigation from "@nextcloud/vue/dist/Components/NcAppNavigation";
const NcAppNavigationItem = () =>
  import("@nextcloud/vue/dist/Components/NcAppNavigationItem");
const NcAppNavigationSettings = () =>
  import("@nextcloud/vue/dist/Components/NcAppNavigationSettings");

import { generateUrl } from "@nextcloud/router";
import { translate as t } from "@nextcloud/l10n";

import Timeline from "./components/Timeline.vue";
import Settings from "./components/Settings.vue";
import FirstStart from "./components/FirstStart.vue";
import Metadata from "./components/Metadata.vue";

import ImageMultiple from "vue-material-design-icons/ImageMultiple.vue";
import FolderIcon from "vue-material-design-icons/Folder.vue";
import Star from "vue-material-design-icons/Star.vue";
import Video from "vue-material-design-icons/PlayCircle.vue";
import AlbumIcon from "vue-material-design-icons/ImageAlbum.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";
import CalendarIcon from "vue-material-design-icons/Calendar.vue";
import PeopleIcon from "vue-material-design-icons/AccountBoxMultiple.vue";
import MarkerIcon from "vue-material-design-icons/MapMarker.vue";
import TagsIcon from "vue-material-design-icons/Tag.vue";
import MapIcon from "vue-material-design-icons/Map.vue";

export default defineComponent({
  name: "App",
  components: {
    NcContent,
    NcAppContent,
    NcAppNavigation,
    NcAppNavigationItem,
    NcAppNavigationSettings,

    Timeline,
    Settings,
    FirstStart,

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
  },

  data: () => ({
    navItems: [],
    metadataComponent: null as any,
  }),

  computed: {
    ncVersion(): number {
      const version = (<any>window.OC).config.version.split(".");
      return Number(version[0]);
    },

    recognize(): string | boolean {
      if (!this.config_recognizeEnabled) {
        return false;
      }

      if (this.config_facerecognitionInstalled) {
        return t("memories", "People (Recognize)");
      }

      return t("memories", "People");
    },

    facerecognition(): string | boolean {
      if (!this.config_facerecognitionInstalled) {
        return false;
      }

      if (this.config_recognizeEnabled) {
        return t("memories", "People (Face Recognition)");
      }

      return t("memories", "People");
    },

    isFirstStart(): boolean {
      return this.config_timelinePath === "EMPTY";
    },

    showAlbums(): boolean {
      return this.config_albumsEnabled;
    },

    removeOuterGap(): boolean {
      return this.ncVersion >= 25;
    },

    showNavigation(): boolean {
      return !this.$route.name?.endsWith("-share");
    },
  },

  watch: {
    route() {
      this.doRouteChecks();
    },
  },

  mounted() {
    this.doRouteChecks();

    // Populate navigation
    this.navItems = this.navItemsAll().filter(
      (item) => typeof item.if === "undefined" || Boolean(item.if)
    );

    // Store CSS variables modified
    const root = document.documentElement;
    const colorPrimary =
      getComputedStyle(root).getPropertyValue("--color-primary");
    root.style.setProperty("--color-primary-select-light", `${colorPrimary}40`);
    root.style.setProperty("--plyr-color-main", colorPrimary);

    // Register sidebar metadata tab
    const OCA = globalThis.OCA;
    if (OCA.Files && OCA.Files.Sidebar) {
      OCA.Files.Sidebar.registerTab(
        new OCA.Files.Sidebar.Tab({
          id: "memories-metadata",
          name: this.t("memories", "EXIF"),
          icon: "icon-details",

          async mount(el, fileInfo, context) {
            if (this.metadataComponent) {
              this.metadataComponent.$destroy();
            }
            this.metadataComponent = new Vue(Metadata as any);
            // Only mount after we have all the info we need
            await this.metadataComponent.update(fileInfo);
            this.metadataComponent.$mount(el);
          },
          update(fileInfo) {
            this.metadataComponent.update(fileInfo);
          },
          destroy() {
            this.metadataComponent.$destroy();
            this.metadataComponent = null;
          },
        })
      );
    }
  },

  methods: {
    navItemsAll() {
      return [
        {
          name: "timeline",
          icon: ImageMultiple,
          title: t("memories", "Timeline"),
        },
        {
          name: "folders",
          icon: FolderIcon,
          title: t("memories", "Folders"),
        },
        {
          name: "favorites",
          icon: Star,
          title: t("memories", "Favorites"),
        },
        {
          name: "videos",
          icon: Video,
          title: t("memories", "Videos"),
        },
        {
          name: "albums",
          icon: AlbumIcon,
          title: t("memories", "Albums"),
          if: this.showAlbums,
        },
        {
          name: "recognize",
          icon: PeopleIcon,
          title: this.recognize,
          if: this.recognize,
        },
        {
          name: "facerecognition",
          icon: PeopleIcon,
          title: this.facerecognition,
          if: this.facerecognition,
        },
        {
          name: "archive",
          icon: ArchiveIcon,
          title: t("memories", "Archive"),
        },
        {
          name: "thisday",
          icon: CalendarIcon,
          title: t("memories", "On this day"),
        },
        {
          name: "places",
          icon: MarkerIcon,
          title: t("memories", "Places"),
          if: this.config_placesGis > 0,
        },
        {
          name: "tags",
          icon: TagsIcon,
          title: t("memories", "Tags"),
          if: this.config_tagsEnabled,
        },
        {
          name: "maps",
          icon: MapIcon,
          title: t("memories", "Maps"),
          if: this.config_mapsEnabled,
        },
        {
          name: "locations",
          icon: MapIcon,
          title: t("memories", "Locations"),
        },
      ];
    },

    async beforeMount() {
      if ("serviceWorker" in navigator) {
        // Use the window load event to keep the page load performant
        window.addEventListener("load", async () => {
          try {
            const url = generateUrl("/apps/memories/service-worker.js");
            const registration = await navigator.serviceWorker.register(url, {
              scope: generateUrl("/apps/memories"),
            });
            console.log("SW registered: ", registration);
          } catch (error) {
            console.error("SW registration failed: ", error);
          }
        });
      } else {
        console.debug("Service Worker is not enabled on this browser.");
      }
    },

    linkClick() {
      const nav: any = this.$refs.nav;
      if (globalThis.windowInnerWidth <= 1024) nav?.toggleNavigation(false);
    },

    doRouteChecks() {
      if (this.$route.name.endsWith("-share")) {
        this.putShareToken(<string>this.$route.params.token);
      }
    },

    putShareToken(token: string) {
      // Viewer looks for an input with ID sharingToken with the value as the token
      // Create this element or update it otherwise files not gonna open
      // https://github.com/nextcloud/viewer/blob/a8c46050fb687dcbb48a022a15a5d1275bf54a8e/src/utils/davUtils.js#L61
      let tokenInput = document.getElementById(
        "sharingToken"
      ) as HTMLInputElement;
      if (!tokenInput) {
        tokenInput = document.createElement("input");
        tokenInput.id = "sharingToken";
        tokenInput.type = "hidden";
        tokenInput.style.display = "none";
        document.body.appendChild(tokenInput);
      }

      tokenInput.value = token;
    },
  },
});
</script>

<style scoped lang="scss">
.outer {
  padding: 0 0 0 44px;
  height: 100%;
  width: 100%;
}

@media (max-width: 768px) {
  .outer {
    padding: 0px;

    // Get rid of padding on img-outer (1px on mobile)
    // Also need to make sure we don't end up with a scrollbar -- see below
    margin-left: -1px;
    width: calc(100% + 3px); // 1px extra here because ... reasons
  }
}
</style>
