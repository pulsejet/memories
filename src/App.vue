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
import { Component, Mixins, Watch } from "vue-property-decorator";

import NcContent from "@nextcloud/vue/dist/Components/NcContent";
import NcAppContent from "@nextcloud/vue/dist/Components/NcAppContent";
import NcAppNavigation from "@nextcloud/vue/dist/Components/NcAppNavigation";
const NcAppNavigationItem = () =>
  import("@nextcloud/vue/dist/Components/NcAppNavigationItem");
const NcAppNavigationSettings = () =>
  import("@nextcloud/vue/dist/Components/NcAppNavigationSettings");

import { generateUrl } from "@nextcloud/router";
import { getCurrentUser } from "@nextcloud/auth";
import { translate as t } from "@nextcloud/l10n";

import Timeline from "./components/Timeline.vue";
import Settings from "./components/Settings.vue";
import FirstStart from "./components/FirstStart.vue";
import Metadata from "./components/Metadata.vue";
import GlobalMixin from "./mixins/GlobalMixin";
import UserConfig from "./mixins/UserConfig";

import ImageMultiple from "vue-material-design-icons/ImageMultiple.vue";
import FolderIcon from "vue-material-design-icons/Folder.vue";
import Star from "vue-material-design-icons/Star.vue";
import Video from "vue-material-design-icons/PlayCircle.vue";
import AlbumIcon from "vue-material-design-icons/ImageAlbum.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";
import CalendarIcon from "vue-material-design-icons/Calendar.vue";
import PeopleIcon from "vue-material-design-icons/AccountBoxMultiple.vue";
import TagsIcon from "vue-material-design-icons/Tag.vue";
import MapIcon from "vue-material-design-icons/Map.vue";

@Component({
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
    TagsIcon,
    MapIcon,
  },
})
export default class App extends Mixins(GlobalMixin, UserConfig) {
  // Outer element

  private metadataComponent!: Metadata;

  private readonly navItemsAll = [
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
      if: (self: any) => self.showAlbums,
    },
    {
      name: "people",
      icon: PeopleIcon,
      title: t("memories", "People"),
      if: (self: any) => self.showRecognizePeople && !self.showFaceRecognition,
    },
    {
      name: "facerecognition",
      icon: PeopleIcon,
      title: t("memories", "People"),
      if: (self: any) => self.showFaceRecognition && !self.showRecognizePeople,
    },
    {
      name: "people",
      icon: PeopleIcon,
      title: t("memories", "People (Recognize)"),
      if: (self: any) => self.showRecognizePeople && self.showFaceRecognition,
    },
    {
      name: "facerecognition",
      icon: PeopleIcon,
      title: t("memories", "People (Face Recognition)"),
      if: (self: any) => self.showFaceRecognition && self.showRecognizePeople,
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
      name: "tags",
      icon: TagsIcon,
      title: t("memories", "Tags"),
      if: (self: any) => self.config_tagsEnabled,
    },
    {
      name: "maps",
      icon: MapIcon,
      title: t("memories", "Maps"),
      if: (self: any) => self.config_mapsEnabled,
    },
  ];

  private navItems = [];

  get ncVersion() {
    const version = (<any>window.OC).config.version.split(".");
    return Number(version[0]);
  }

  get showRecognizePeople() {
    return this.config_recognizeEnabled || getCurrentUser()?.isAdmin;
  }

  get showFaceRecognition() {
    return this.config_facerecognitionInstalled || getCurrentUser()?.isAdmin;
  }

  get isFirstStart() {
    return this.config_timelinePath === "EMPTY";
  }

  get showAlbums() {
    return this.config_albumsEnabled;
  }

  get removeOuterGap() {
    return this.ncVersion >= 25;
  }

  get showNavigation() {
    return this.$route.name !== "folder-share";
  }

  @Watch("$route")
  routeChanged() {
    this.doRouteChecks();
  }

  mounted() {
    this.doRouteChecks();

    // Populate navigation
    this.navItems = this.navItemsAll.filter(
      (item) => !item.if || item.if(this)
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
            this.metadataComponent = new Metadata({
              // Better integration with vue parent component
              parent: context,
            });
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
  }

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
  }

  linkClick() {
    const nav: any = this.$refs.nav;
    if (globalThis.windowInnerWidth <= 1024) nav?.toggleNavigation(false);
  }

  doRouteChecks() {
    if (this.$route.name === "folder-share") {
      this.putFolderShareToken(this.$route.params.token);
    }
  }

  putFolderShareToken(token: string) {
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
  }
}
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

<style lang="scss">
body {
  overflow: hidden;
}

// Nextcloud 25+: get rid of gap and border radius at right
#content-vue.remove-gap {
  // was var(--body-container-radius)
  // now set on #app-navigation-vue
  border-radius: 0;
  width: calc(100% - var(--body-container-margin) * 1); // was *2

  // Reduce size of navigation. NC <25 doesn't like this on mobile.
  #app-navigation-vue {
    max-width: 250px;
  }
}

// Prevent content overflow on NC <25
#content-vue {
  max-height: 100vh;

  // https://bugs.webkit.org/show_bug.cgi?id=160953
  overflow: visible;
  #app-navigation-vue {
    border-top-left-radius: var(--body-container-radius);
    border-bottom-left-radius: var(--body-container-radius);
  }
}

// Top bar is above everything else on mobile
body.has-top-bar header {
  @media (max-width: 1024px) {
    z-index: 0 !important;
  }
}
body.has-viewer header {
  z-index: 0 !important;
}

// Hide horizontal scrollbar on mobile
// For the padding removal above
#app-content-vue {
  overflow-x: hidden;
}

// Prevent sidebar from becoming too big
aside.app-sidebar {
  max-width: 360px !important;
}

// Fill all available space
.fill-block {
  width: 100%;
  height: 100%;
  display: block;
}

:root {
  --livephoto-img-transition: opacity 0.4s linear, transform 0.3s ease-in-out;
}

// Live photo transitions
.memories-livephoto {
  position: relative;
  overflow: hidden;

  img,
  video {
    position: absolute;
    padding: inherit;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: block;
    transition: var(--livephoto-img-transition);
  }

  video,
  &.playing.canplay img {
    opacity: 0;
  }
  img,
  &.playing.canplay video {
    opacity: 1;
  }
  &.playing.canplay img {
    transform: scale(1.05);
  }
}
</style>
