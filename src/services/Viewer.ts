import Vue from "vue";
import { IDay, IFileInfo, IPhoto, IRow, IRowType } from "../types";
import { showError } from "@nextcloud/dialogs";
import { subscribe } from "@nextcloud/event-bus";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import { Route } from "vue-router";
import { getPreviewUrl } from "./FileUtils";
import * as dav from "./DavRequests";
import * as utils from "./Utils";

import PhotoSwipe, { PhotoSwipeOptions } from "photoswipe";
import "photoswipe/style.css";

import DeleteIcon from "vue-material-design-icons/Delete.vue";

// Key to store sidebar state
const SIDEBAR_KEY = "memories:sidebar-open";

// Options
type opts_t = {
  ondelete: (photos: IPhoto[]) => void;
  fetchDay: (dayId: number) => void;
};

export class ViewerManager {
  /** Delete click callback */
  private deleteClick!: () => void;

  constructor(private opts: opts_t) {}

  private getVueBtn(typ: any) {
    const btn = new (Vue.extend(typ))({
      propsData: {
        size: 24,
      },
    });
    btn.$mount();
    return btn.$el;
  }

  private getBaseBox(args: PhotoSwipeOptions) {
    const photoswipe = new PhotoSwipe({
      counter: true,
      loop: false,
      ...args,
    });

    photoswipe.addFilter("uiElement", (element, data) => {
      // add button-vue class if button
      if (element.classList.contains("pswp__button")) {
        element.classList.add("button-vue");
      }
      return element;
    });

    photoswipe.on("uiRegister", () => {
      photoswipe.ui.registerElement({
        name: "delete-button",
        ariaLabel: "Delete",
        order: 9,
        isButton: true,
        html: this.getVueBtn(DeleteIcon).outerHTML,
        onClick: () => this.deleteClick(),
      });
    });

    return photoswipe;
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

    const list = [...anchorPhoto.d.detail];
    const days = new Map<number, IDay>();
    const dayIds = [];

    let globalCount = 0;
    let globalAnchor = -1;
    let startIndex = -1;

    for (const r of rows) {
      if (r.type === IRowType.HEAD) {
        if (r.day.dayid == anchorPhoto.d.dayid) {
          startIndex = r.day.detail.findIndex(
            (p) => p.fileid === anchorPhoto.fileid
          );
          globalAnchor = globalCount;
        }

        globalCount += r.day.count;
        days.set(r.day.dayid, r.day);
        dayIds.push(r.day.dayid);
      }
    }

    const photoswipe = this.getBaseBox({
      index: globalAnchor + startIndex,
    });

    photoswipe.addFilter("numItems", (numItems) => {
      return globalCount;
    });

    photoswipe.addFilter("itemData", (itemData, index) => {
      // Get photo object from list
      let idx = index - globalAnchor;
      if (idx < 0) {
        // Load previous day
        const firstDayId = list[0].d.dayid;
        const firstDayIdx = utils.binarySearch(dayIds, firstDayId);
        if (firstDayIdx === 0) {
          // No previous day
          return {};
        }
        const prevDayId = dayIds[firstDayIdx - 1];
        const prevDay = days.get(prevDayId);
        if (!prevDay.detail) {
          console.error("[BUG] No detail for previous day");
          return {};
        }
        list.unshift(...prevDay.detail);
        globalAnchor -= prevDay.count;
      } else if (idx >= list.length) {
        // Load next day
        const lastDayId = list[list.length - 1].d.dayid;
        const lastDayIdx = utils.binarySearch(dayIds, lastDayId);
        if (lastDayIdx === dayIds.length - 1) {
          // No next day
          return {};
        }
        const nextDayId = dayIds[lastDayIdx + 1];
        const nextDay = days.get(nextDayId);
        if (!nextDay.detail) {
          console.error("[BUG] No detail for next day");
          return {};
        }
        list.push(...nextDay.detail);
      }

      idx = index - globalAnchor;
      const photo = list[idx];

      // Something went really wrong
      if (!photo) {
        return {};
      }

      // Preload next and previous 3 days
      const dayIdx = utils.binarySearch(dayIds, photo.d.dayid);
      const preload = (idx: number) => {
        if (idx > 0 && idx < dayIds.length && !days.get(dayIds[idx]).detail) {
          this.opts.fetchDay(dayIds[idx]);
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

    photoswipe.addFilter("thumbEl", (thumbEl, data, index) => {
      const photo = list[index - globalAnchor];
      return this.thumbElem(photo) || thumbEl;
    });

    this.deleteClick = () => {
      const idx = photoswipe.currIndex - globalAnchor;
      const spliced = list.splice(idx, 1);
      globalCount--;
      for (let i = idx - 3; i <= idx + 3; i++) {
        photoswipe.refreshSlideContent(i + globalAnchor);
      }
      this.opts.ondelete(spliced);
    };

    photoswipe.init();
  }

  private thumbElem(photo: IPhoto) {
    if (!photo) return;
    return document.getElementById(
      `memories-photo-${photo.key || photo.fileid}`
    );
  }
}
