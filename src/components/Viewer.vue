<template>
  <div
    class="memories_viewer outer"
    v-if="show"
    :class="{ fullyOpened }"
    :style="{ width: outerWidth }"
  >
    <div class="inner" ref="inner">
      <div class="top-bar" v-if="photoswipe" :class="{ opened }">
        <NcActions :inline="4" container=".memories_viewer .pswp">
          <NcActionButton
            :aria-label="t('memories', 'Delete')"
            @click="deleteCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Delete") }}
            <template #icon> <DeleteIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Favorite')"
            @click="favoriteCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Favorite") }}
            <template #icon>
              <StarIcon v-if="isFavorite()" :size="24" />
              <StarOutlineIcon v-else :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Sidebar')"
            @click="toggleSidebar"
            :close-after-click="true"
          >
            {{ t("memories", "Sidebar") }}
            <template #icon>
              <InfoIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Download')"
            @click="downloadCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Download") }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Emit, Mixins } from "vue-property-decorator";

import GlobalMixin from "../mixins/GlobalMixin";
import { IDay, IPhoto, IRow, IRowType } from "../types";

import { NcActions, NcActionButton } from "@nextcloud/vue";
import { subscribe, unsubscribe } from "@nextcloud/event-bus";

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";
import { getPreviewUrl } from "../services/FileUtils";

import PhotoSwipe, { PhotoSwipeOptions } from "photoswipe";
import "photoswipe/style.css";

import videojs from "video.js";
import "video.js/dist/video-js.css";

import DeleteIcon from "vue-material-design-icons/Delete.vue";
import StarIcon from "vue-material-design-icons/Star.vue";
import StarOutlineIcon from "vue-material-design-icons/StarOutline.vue";
import DownloadIcon from "vue-material-design-icons/Download.vue";
import InfoIcon from "vue-material-design-icons/InformationOutline.vue";

@Component({
  components: {
    NcActions,
    NcActionButton,
    DeleteIcon,
    StarIcon,
    StarOutlineIcon,
    DownloadIcon,
    InfoIcon,
  },
})
export default class Viewer extends Mixins(GlobalMixin) {
  @Emit("deleted") deleted(photos: IPhoto[]) {}
  @Emit("fetchDay") fetchDay(dayId: number) {}
  @Emit("updateLoading") updateLoading(delta: number) {}

  private show = false;
  private opened = false;
  private fullyOpened = false;
  private sidebarOpen = false;
  private sidebarWidth = 400;
  private outerWidth = "100vw";

  /** Base dialog */
  private photoswipe: PhotoSwipe | null = null;

  private list: IPhoto[] = [];
  private days = new Map<number, IDay>();
  private dayIds: number[] = [];

  private globalCount = 0;
  private globalAnchor = -1;

  mounted() {
    subscribe("files:sidebar:opened", this.handleAppSidebarOpen);
    subscribe("files:sidebar:closed", this.handleAppSidebarClose);
  }

  beforeDestroy() {
    unsubscribe("files:sidebar:opened", this.handleAppSidebarOpen);
    unsubscribe("files:sidebar:closed", this.handleAppSidebarClose);
  }

  /** Get the currently open photo */
  private getCurrentPhoto() {
    if (!this.list.length || !this.photoswipe) {
      return null;
    }
    const idx = this.photoswipe.currIndex - this.globalAnchor;
    if (idx < 0 || idx >= this.list.length) {
      return null;
    }
    return this.list[idx];
  }

  /** Create the base photoswipe object */
  private async createBase(args: PhotoSwipeOptions) {
    this.show = true;
    await this.$nextTick();

    this.photoswipe = new PhotoSwipe({
      counter: true,
      zoom: false,
      loop: false,
      bgOpacity: 1,
      appendToEl: this.$refs.inner as HTMLElement,
      preload: [2, 2],
      getViewportSizeFn: () => {
        const sidebarWidth = this.sidebarOpen ? this.sidebarWidth : 0;
        this.outerWidth = `calc(100vw - ${sidebarWidth}px)`;
        return {
          x: window.innerWidth - sidebarWidth,
          y: window.innerHeight,
        };
      },
      ...args,
    });

    // Debugging only
    globalThis.photoswipe = this.photoswipe;

    // Monkey patch for focus trapping in sidebar
    const _onFocusIn = this.photoswipe.keyboard._onFocusIn;
    this.photoswipe.keyboard._onFocusIn = (e: FocusEvent) => {
      if (e.target instanceof HTMLElement) {
        if (
          e.target.closest("aside.app-sidebar") ||
          e.target.closest(".v-popper__popper")
        ) {
          return;
        }
      }
      _onFocusIn.call(this.photoswipe.keyboard, e);
    };

    // Refresh sidebar on change
    this.photoswipe.on("change", () => {
      if (this.sidebarOpen) {
        this.openSidebar();
      }
    });

    // Make sure buttons are styled properly
    this.photoswipe.addFilter("uiElement", (element, data) => {
      // add button-vue class if button
      if (element.classList.contains("pswp__button")) {
        element.classList.add("button-vue");
      }
      return element;
    });

    // Total number of photos in this view
    this.photoswipe.addFilter("numItems", (numItems) => {
      return this.globalCount;
    });

    // Put viewer over everything else
    const navElem = document.getElementById("app-navigation-vue");
    const klass = "has-viewer";
    this.photoswipe.on("beforeOpen", () => {
      document.body.classList.add(klass);
      navElem.style.zIndex = "0";
    });
    this.photoswipe.on("openingAnimationStart", () => {
      this.fullyOpened = false;
      this.opened = true;
      if (this.sidebarOpen) {
        this.openSidebar();
      }
    });
    this.photoswipe.on("openingAnimationEnd", () => {
      this.fullyOpened = true;
    });
    this.photoswipe.on("close", () => {
      this.fullyOpened = false;
      this.opened = false;
      this.hideSidebar();
    });
    this.photoswipe.on("tapAction", () => {
      this.opened = !this.opened; // toggle-controls
    });
    this.photoswipe.on("destroy", () => {
      document.body.classList.remove(klass);
      navElem.style.zIndex = "";

      // reset everything
      this.show = false;
      this.opened = false;
      this.fullyOpened = false;
      this.photoswipe = null;
      this.list = [];
      this.days.clear();
      this.dayIds = [];
      this.globalCount = 0;
      this.globalAnchor = -1;
    });

    // Video support
    this.photoswipe.on("contentLoad", (e) => {
      const { content, isLazy } = e;
      if (content.data.photo.flag & this.c.FLAG_IS_VIDEO) {
        e.preventDefault();

        content.type = "video";

        // Create video element
        content.videoElement = document.createElement("video") as any;
        content.videoElement.classList.add("video-js");

        // Add child with source element
        const source = document.createElement("source");
        source.src = `http://localhost:8025/remote.php/dav/${content.data.photo.filename}`;
        source.type = content.data.photo.mimetype;
        content.videoElement.appendChild(source);

        // Create container div
        content.element = document.createElement("div");
        content.element.appendChild(content.videoElement);

        // Init videojs
        videojs(content.videoElement, {
          fluid: true,
          autoplay: content.data.playvideo,
          controls: true,
          preload: "metadata",
          muted: true,
        });
      }
    });

    // Play video on open slide
    this.photoswipe.on("slideActivate", (e) => {
      const { slide } = e;
      if (slide.data.photo.flag & this.c.FLAG_IS_VIDEO) {
        setTimeout(() => {
          slide.content.element.querySelector("video")?.play();
        }, 500);
      }
    });

    // Pause video on close slide
    this.photoswipe.on("slideDeactivate", (e) => {
      const { slide } = e;
      if (slide.data.photo.flag & this.c.FLAG_IS_VIDEO) {
        slide.content.element.querySelector("video")?.pause();
      }
    });

    return this.photoswipe;
  }

  /** Open using start photo and rows list */
  public async open(anchorPhoto: IPhoto, rows?: IRow[]) {
    this.list = [...anchorPhoto.d.detail];
    let startIndex = -1;

    for (const r of rows) {
      if (r.type === IRowType.HEAD) {
        if (r.day.dayid == anchorPhoto.d.dayid) {
          startIndex = r.day.detail.findIndex(
            (p) => p.fileid === anchorPhoto.fileid
          );
          this.globalAnchor = this.globalCount;
        }

        this.globalCount += r.day.count;
        this.days.set(r.day.dayid, r.day);
        this.dayIds.push(r.day.dayid);
      }
    }

    await this.createBase({
      index: this.globalAnchor + startIndex,
    });

    this.photoswipe.addFilter("itemData", (itemData, index) => {
      // Get photo object from list
      let idx = index - this.globalAnchor;
      if (idx < 0) {
        // Load previous day
        const firstDayId = this.list[0].d.dayid;
        const firstDayIdx = utils.binarySearch(this.dayIds, firstDayId);
        if (firstDayIdx === 0) {
          // No previous day
          return {};
        }
        const prevDayId = this.dayIds[firstDayIdx - 1];
        const prevDay = this.days.get(prevDayId);
        if (!prevDay.detail) {
          console.error("[BUG] No detail for previous day");
          return {};
        }
        this.list.unshift(...prevDay.detail);
        this.globalAnchor -= prevDay.count;
      } else if (idx >= this.list.length) {
        // Load next day
        const lastDayId = this.list[this.list.length - 1].d.dayid;
        const lastDayIdx = utils.binarySearch(this.dayIds, lastDayId);
        if (lastDayIdx === this.dayIds.length - 1) {
          // No next day
          return {};
        }
        const nextDayId = this.dayIds[lastDayIdx + 1];
        const nextDay = this.days.get(nextDayId);
        if (!nextDay.detail) {
          console.error("[BUG] No detail for next day");
          return {};
        }
        this.list.push(...nextDay.detail);
      }

      idx = index - this.globalAnchor;
      const photo = this.list[idx];

      // Something went really wrong
      if (!photo) {
        return {};
      }

      // Preload next and previous 3 days
      const dayIdx = utils.binarySearch(this.dayIds, photo.d.dayid);
      const preload = (idx: number) => {
        if (
          idx > 0 &&
          idx < this.dayIds.length &&
          !this.days.get(this.dayIds[idx]).detail
        ) {
          this.fetchDay(this.dayIds[idx]);
        }
      };
      preload(dayIdx - 1);
      preload(dayIdx - 2);
      preload(dayIdx - 3);
      preload(dayIdx + 1);
      preload(dayIdx + 2);
      preload(dayIdx + 3);

      // Get thumb image
      const thumbSrc: string =
        this.thumbElem(photo)?.querySelector("img")?.getAttribute("src") ||
        getPreviewUrl(photo, false, 256);

      // Get full image
      return {
        src: getPreviewUrl(photo, false, 256),
        msrc: thumbSrc,
        width: photo.w || undefined,
        height: photo.h || undefined,
        thumbCropped: true,
        photo: photo,
      };
    });

    this.photoswipe.addFilter("thumbEl", (thumbEl, data, index) => {
      const photo = this.list[index - this.globalAnchor];
      return this.thumbElem(photo) || thumbEl;
    });

    this.photoswipe.init();
  }

  /** Get element for thumbnail if it exists */
  private thumbElem(photo: IPhoto) {
    if (!photo) return;
    return document.getElementById(
      `memories-photo-${photo.key || photo.fileid}`
    );
  }

  /** Delete this photo and refresh */
  private async deleteCurrent() {
    const idx = this.photoswipe.currIndex - this.globalAnchor;

    // Delete with WebDAV
    try {
      this.updateLoading(1);
      for await (const p of dav.deletePhotos([this.list[idx]])) {
        if (!p[0]) return;
      }
    } finally {
      this.updateLoading(-1);
    }

    const spliced = this.list.splice(idx, 1);
    this.globalCount--;
    for (let i = idx - 3; i <= idx + 3; i++) {
      this.photoswipe.refreshSlideContent(i + this.globalAnchor);
    }
    this.deleted(spliced);
  }

  /** Is the current photo a favorite */
  private isFavorite() {
    const p = this.getCurrentPhoto();
    if (!p) return false;
    return Boolean(p.flag & this.c.FLAG_IS_FAVORITE);
  }

  /** Favorite the current photo */
  private async favoriteCurrent() {
    const photo = this.getCurrentPhoto();
    const val = !this.isFavorite();
    try {
      this.updateLoading(1);
      for await (const p of dav.favoritePhotos([photo], val)) {
        if (!p[0]) return;
      }
    } finally {
      this.updateLoading(-1);
    }

    // Set flag on success
    if (val) {
      photo.flag |= this.c.FLAG_IS_FAVORITE;
    } else {
      photo.flag &= ~this.c.FLAG_IS_FAVORITE;
    }
  }

  /** Download the current photo */
  private async downloadCurrent() {
    const photo = this.getCurrentPhoto();
    if (!photo) return;
    dav.downloadFilesByIds([photo]);
  }

  /** Open the sidebar */
  private async openSidebar(photo?: IPhoto) {
    const fInfo = await dav.getFiles([photo || this.getCurrentPhoto()]);
    globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(true);
    globalThis.OCA.Files.Sidebar.open(fInfo[0].filename);
  }

  private async updateSizeWithoutAnim() {
    const wasFullyOpened = this.fullyOpened;
    this.fullyOpened = false;
    this.photoswipe.updateSize();
    await new Promise((resolve) => setTimeout(resolve, 200));
    this.fullyOpened = wasFullyOpened;
  }

  private handleAppSidebarOpen() {
    if (this.show && this.photoswipe) {
      const sidebar: HTMLElement = document.querySelector("aside.app-sidebar");
      if (sidebar) {
        this.sidebarWidth = sidebar.offsetWidth - 2;
      }

      this.sidebarOpen = true;
      this.updateSizeWithoutAnim();
    }
  }

  private handleAppSidebarClose() {
    if (this.show && this.photoswipe && this.fullyOpened) {
      this.sidebarOpen = false;
      this.updateSizeWithoutAnim();
    }
  }

  /** Hide the sidebar, without marking it as closed */
  private hideSidebar() {
    globalThis.OCA?.Files?.Sidebar?.close();
    globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(false);
  }

  /** Close the sidebar */
  private closeSidebar() {
    this.hideSidebar();
    this.sidebarOpen = false;
    this.photoswipe.updateSize();
  }

  /** Toggle the sidebar visibility */
  private toggleSidebar() {
    if (this.sidebarOpen) {
      this.closeSidebar();
    } else {
      this.openSidebar();
    }
  }
}
</script>

<style lang="scss" scoped>
.outer {
  z-index: 3000;
  width: 100vw;
  height: 100vh;
  position: fixed;
  top: 0;
  left: 0;
  overflow: hidden;
  color: white;
}

.top-bar {
  z-index: 100001;
  position: absolute;
  top: 8px;
  right: 50px;

  :deep .button-vue--icon-only {
    color: white;
    background-color: transparent !important;
  }

  transition: opacity 0.2s ease-in-out;
  opacity: 0;
  &.opened {
    opacity: 1;
  }
}

.fullyOpened :deep .pswp__container {
  @media (min-width: 1024px) {
    transition: transform var(--pswp-transition-duration) ease !important;
  }
}

.inner,
.inner :deep .pswp {
  width: inherit;
}

:deep .pswp {
  .pswp__zoom-wrap {
    width: 100%;
  }

  .pswp__button {
    color: white;

    &,
    * {
      cursor: pointer;
    }
  }
  .pswp__icn-shadow {
    display: none;
  }
}
</style>