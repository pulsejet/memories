<template>
  <div class="memories_viewer outer">
    <div class="inner" ref="inner">
      <div class="top-bar" v-if="photoswipe">
        <NcActions :inline="2" container=".memories_viewer .pswp">
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

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";
import { getPreviewUrl } from "../services/FileUtils";

import PhotoSwipe, { PhotoSwipeOptions } from "photoswipe";
import "photoswipe/style.css";

import DeleteIcon from "vue-material-design-icons/Delete.vue";
import StarIcon from "vue-material-design-icons/Star.vue";
import StarOutlineIcon from "vue-material-design-icons/StarOutline.vue";

@Component({
  components: {
    NcActions,
    NcActionButton,
    DeleteIcon,
    StarIcon,
    StarOutlineIcon,
  },
})
export default class Viewer extends Mixins(GlobalMixin) {
  @Emit("deleted") deleted(photos: IPhoto[]) {}
  @Emit("fetchDay") fetchDay(dayId: number) {}
  @Emit("updateLoading") updateLoading(delta: number) {}

  /** Base dialog */
  private photoswipe: PhotoSwipe | null = null;

  private list: IPhoto[] = [];
  private days = new Map<number, IDay>();
  private dayIds: number[] = [];

  private globalCount = 0;
  private globalAnchor = -1;

  private getBaseBox(args: PhotoSwipeOptions) {
    this.photoswipe = new PhotoSwipe({
      counter: true,
      zoom: false,
      loop: false,
      bgOpacity: 1,
      appendToEl: this.$refs.inner as HTMLElement,
      ...args,
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
    const contentElem = document.getElementById("content-vue");
    const navElem = document.getElementById("app-navigation-vue");
    const klass = "has-viewer";
    this.photoswipe.on("beforeOpen", () => {
      contentElem.classList.add(klass);
      navElem.style.zIndex = "0";
    });
    this.photoswipe.on("destroy", () => {
      contentElem.classList.remove(klass);
      navElem.style.zIndex = "";

      // reset everything
      this.photoswipe = null;
      this.list = [];
      this.days.clear();
      this.dayIds = [];
      this.globalCount = 0;
      this.globalAnchor = -1;
    });

    return this.photoswipe;
  }

  public async open(anchorPhoto: IPhoto, rows?: IRow[]) {
    //   list = list || photo.d?.detail;
    //   if (!list?.length) return;
    //   // Repopulate map
    //   this.photoMap.clear();
    //   for (const p of list) {
    //     this.photoMap.set(p.fileid, p);
    //   }
    //   // Get file infos
    //   let fileInfos: IFileInfo[];
    //   try {
    //     this.updateLoading(1);
    //     fileInfos = await dav.getFiles(list);
    //   } catch (e) {
    //     console.error("Failed to load fileInfos", e);
    //     showError("Failed to load fileInfos");
    //     return;
    //   } finally {
    //     this.updateLoading(-1);
    //   }
    //   if (fileInfos.length === 0) {
    //     return;
    //   }
    //   // Fix sorting of the fileInfos
    //   const itemPositions = {};
    //   for (const [index, p] of list.entries()) {
    //     itemPositions[p.fileid] = index;
    //   }
    //   fileInfos.sort(function (a, b) {
    //     return itemPositions[a.fileid] - itemPositions[b.fileid];
    //   });
    //   // Get this photo in the fileInfos
    //   const fInfo = fileInfos.find((d) => Number(d.fileid) === photo.fileid);
    //   if (!fInfo) {
    //     showError(t("memories", "Cannot find this photo anymore!"));
    //     return;
    //   }
    //   // Check viewer > 2.0.0
    //   const viewerVersion: string = globalThis.OCA.Viewer.version;
    //   const viewerMajor = Number(viewerVersion.split(".")[0]);
    //   // Open Nextcloud viewer
    //   globalThis.OCA.Viewer.open({
    //     fileInfo: fInfo,
    //     path: viewerMajor < 2 ? fInfo.filename : undefined, // Only specify path upto Nextcloud 24
    //     list: fileInfos, // file list
    //     canLoop: false, // don't loop
    //     onClose: () => {
    //       // on viewer close
    //       if (globalThis.OCA.Files.Sidebar.file) {
    //         localStorage.setItem(SIDEBAR_KEY, "1");
    //       } else {
    //         localStorage.removeItem(SIDEBAR_KEY);
    //       }
    //       globalThis.OCA.Files.Sidebar.close();
    //     },
    //   });
    //   // Restore sidebar state
    //   if (localStorage.getItem(SIDEBAR_KEY) === "1") {
    //     globalThis.OCA.Files.Sidebar.open(fInfo.filename);
    //   }

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

    this.getBaseBox({
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
      };
    });

    this.photoswipe.addFilter("thumbEl", (thumbEl, data, index) => {
      const photo = this.list[index - this.globalAnchor];
      return this.thumbElem(photo) || thumbEl;
    });

    this.photoswipe.init();
  }

  private thumbElem(photo: IPhoto) {
    if (!photo) return;
    return document.getElementById(
      `memories-photo-${photo.key || photo.fileid}`
    );
  }

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

  private isFavorite() {
    const idx = this.photoswipe.currIndex - this.globalAnchor;
    return Boolean(this.list[idx].flag & this.c.FLAG_IS_FAVORITE);
  }

  private async favoriteCurrent() {
    const idx = this.photoswipe.currIndex - this.globalAnchor;
    const photo = this.list[idx];
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
}
</script>

<style lang="scss" scoped>
.outer {
  z-index: 3000;
  width: 100vw;
  height: 30vh;
  position: absolute;
  top: 0;
  left: 0;
  overflow: hidden;
  color: white;
}

.top-bar {
  z-index: 100001;
  position: fixed;
  top: 8px;
  right: 50px;

  :deep .button-vue--icon-only {
    color: white;
    background-color: transparent !important;
  }
}
</style>