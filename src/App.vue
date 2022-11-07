<template>
  <FirstStart v-if="isFirstStart" />

  <NcContent
    app-name="memories"
    v-else
    :class="{
      'remove-gap': removeOuterGap,
    }"
  >
    <NcAppNavigation v-if="showNavigation">
      <template id="app-memories-navigation" #list>
        <NcAppNavigationItem
          :to="{ name: 'timeline' }"
          :title="t('memories', 'Timeline')"
          exact
        >
          <ImageMultiple slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'folders' }"
          :title="t('memories', 'Folders')"
        >
          <FolderIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'favorites' }"
          :title="t('memories', 'Favorites')"
        >
          <Star slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'videos' }"
          :title="t('memories', 'Videos')"
        >
          <Video slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'albums' }"
          :title="t('memories', 'Albums')"
          v-if="showAlbums"
        >
          <AlbumIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'people' }"
          :title="t('memories', 'People')"
          v-if="showPeople"
        >
          <PeopleIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'archive' }"
          :title="t('memories', 'Archive')"
        >
          <ArchiveIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'thisday' }"
          :title="t('memories', 'On this day')"
        >
          <CalendarIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'tags' }"
          v-if="config_tagsEnabled"
          :title="t('memories', 'Tags')"
        >
          <TagsIcon slot="icon" :size="20" />
        </NcAppNavigationItem>
        <NcAppNavigationItem
          :to="{ name: 'maps' }"
          v-if="config_mapsEnabled"
          :title="t('memories', 'Maps')"
        >
          <MapIcon slot="icon" :size="20" />
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
import {
  NcContent,
  NcAppContent,
  NcAppNavigation,
  NcAppNavigationItem,
  NcAppNavigationSettings,
} from "@nextcloud/vue";
import { generateUrl } from "@nextcloud/router";
import { getCurrentUser } from "@nextcloud/auth";

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

  get ncVersion() {
    const version = (<any>window.OC).config.version.split(".");
    return Number(version[0]);
  }

  get showPeople() {
    return this.config_recognizeEnabled || getCurrentUser()?.isAdmin;
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

    // Store CSS variables modified
    const root = document.documentElement;
    const colorPrimary =
      getComputedStyle(root).getPropertyValue("--color-primary");
    root.style.setProperty("--color-primary-select-light", `${colorPrimary}40`);

    // Register sidebar metadata tab
    const OCA = globalThis.OCA;
    if (OCA.Files && OCA.Files.Sidebar) {
      OCA.Files.Sidebar.registerTab(
        new OCA.Files.Sidebar.Tab({
          id: "memories-metadata",
          name: this.t("memories", "Metadata"),
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
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;

  width: calc(100% - var(--body-container-margin) * 1); // was *2
}

// Prevent content overflow on NC <25
#content-vue {
  max-height: 100vh;
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

// Patch viewer to remove the title and
// make the image fill the entire screen
.viewer {
  .modal-title {
    display: none;
  }
  .modal-wrapper .modal-container {
    top: 0 !important;
    bottom: 0 !important;

    .viewer__image-editor {
      top: 0 !important;
      bottom: 0 !important;
    }
  }
}

// Hide horizontal scrollbar on mobile
// For the padding removal above
#app-content-vue {
  overflow-x: hidden;
}

// Fill all available space
.fill-block {
  width: 100%;
  height: 100%;
  display: block;
}
</style>
