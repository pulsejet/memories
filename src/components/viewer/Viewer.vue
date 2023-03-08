<template>
  <div
    class="memories_viewer outer"
    v-if="show"
    :class="{ fullyOpened, slideshowTimer }"
    :style="{ width: outerWidth }"
    @fullscreenchange="fullscreenChange"
    @keydown="keydown"
  >
    <ImageEditor
      v-if="editorOpen"
      :mime="currentPhoto.mimetype"
      :src="editorDownloadLink"
      :fileid="currentPhoto.fileid"
      @close="editorOpen = false"
    />

    <div
      class="inner"
      ref="inner"
      v-show="!editorOpen"
      @pointermove.passive="setUiVisible"
      @pointerdown.passive="setUiVisible"
    >
      <div class="top-bar" v-if="photoswipe" :class="{ showControls }">
        <NcActions
          :inline="numInlineActions"
          container=".memories_viewer .pswp"
        >
          <NcActionButton
            v-if="canShare"
            :aria-label="t('memories', 'Share')"
            @click="shareCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Share") }}
            <template #icon> <ShareIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic && !routeIsAlbum"
            :aria-label="t('memories', 'Delete')"
            @click="deleteCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Delete") }}
            <template #icon> <DeleteIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic && routeIsAlbum"
            :aria-label="t('memories', 'Remove from album')"
            @click="deleteCurrent"
            :close-after-click="true"
          >
            {{ t("memories", "Remove from album") }}
            <template #icon> <AlbumRemoveIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic"
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
            v-if="!routeIsPublic"
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
            v-if="canEdit && !routeIsPublic"
            :aria-label="t('memories', 'Edit')"
            @click="openEditor"
            :close-after-click="true"
          >
            {{ t("memories", "Edit") }}
            <template #icon>
              <TuneIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Download')"
            @click="downloadCurrent"
            :close-after-click="true"
            v-if="!this.state_noDownload"
          >
            {{ t("memories", "Download") }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="!this.state_noDownload && currentPhoto?.liveid"
            :aria-label="t('memories', 'Download Video')"
            @click="downloadCurrentLiveVideo"
            :close-after-click="true"
          >
            {{ t("memories", "Download Video") }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic && !routeIsAlbum"
            :aria-label="t('memories', 'View in folder')"
            @click="viewInFolder"
            :close-after-click="true"
          >
            {{ t("memories", "View in folder") }}
            <template #icon>
              <OpenInNewIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Slideshow')"
            @click="startSlideshow"
            :close-after-click="true"
          >
            {{ t("memories", "Slideshow") }}
            <template #icon>
              <SlideshowIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Edit Metadata')"
            v-if="!routeIsPublic"
            @click="editMetadata"
            :close-after-click="true"
          >
            {{ t("memories", "Edit Metadata") }}
            <template #icon>
              <EditFileIcon :size="24" />
            </template>
          </NcActionButton>
        </NcActions>
      </div>

      <div
        class="bottom-bar"
        v-if="photoswipe"
        :class="{ showControls, showBottomBar }"
      >
        <div class="exif title" v-if="currentPhoto?.imageInfo?.exif?.Title">
          {{ currentPhoto.imageInfo.exif.Title }}
        </div>
        <div
          class="exif description"
          v-if="currentPhoto?.imageInfo?.exif?.Description"
        >
          {{ currentPhoto.imageInfo.exif.Description }}
        </div>
        <div class="exif date" v-if="currentDateTaken">
          {{ currentDateTaken }}
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import { IDay, IPhoto, IRow, IRowType } from "../../types";

import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";
import axios from "@nextcloud/axios";
import { subscribe, unsubscribe } from "@nextcloud/event-bus";
import { showError } from "@nextcloud/dialogs";
import { getRootUrl } from "@nextcloud/router";

import { getPreviewUrl } from "../../services/FileUtils";
import { getDownloadLink } from "../../services/DavRequests";
import { API } from "../../services/API";
import { fetchImage } from "../frame/XImgCache";
import * as dav from "../../services/DavRequests";
import * as utils from "../../services/Utils";

import ImageEditor from "./ImageEditor.vue";
import PhotoSwipe, { PhotoSwipeOptions } from "photoswipe";
import "photoswipe/style.css";
import PsImage from "./PsImage";
import PsVideo from "./PsVideo";
import PsLivePhoto from "./PsLivePhoto";

import ShareIcon from "vue-material-design-icons/ShareVariant.vue";
import DeleteIcon from "vue-material-design-icons/TrashCanOutline.vue";
import StarIcon from "vue-material-design-icons/Star.vue";
import StarOutlineIcon from "vue-material-design-icons/StarOutline.vue";
import DownloadIcon from "vue-material-design-icons/Download.vue";
import InfoIcon from "vue-material-design-icons/InformationOutline.vue";
import OpenInNewIcon from "vue-material-design-icons/OpenInNew.vue";
import TuneIcon from "vue-material-design-icons/Tune.vue";
import SlideshowIcon from "vue-material-design-icons/PlayBox.vue";
import EditFileIcon from "vue-material-design-icons/FileEdit.vue";
import AlbumRemoveIcon from "vue-material-design-icons/BookRemove.vue";

const SLIDESHOW_MS = 5000;

export default defineComponent({
  name: "Viewer",
  components: {
    NcActions,
    NcActionButton,
    ImageEditor,
    ShareIcon,
    DeleteIcon,
    StarIcon,
    StarOutlineIcon,
    DownloadIcon,
    InfoIcon,
    OpenInNewIcon,
    TuneIcon,
    SlideshowIcon,
    EditFileIcon,
    AlbumRemoveIcon,
  },

  data: () => ({
    isOpen: false,
    originalTitle: null,
    editorOpen: false,

    show: false,
    showControls: false,
    fullyOpened: false,
    sidebarOpen: false,
    sidebarWidth: 400,
    outerWidth: "100vw",

    /** User interaction detection */
    activityTimer: 0,

    /** Base dialog */
    photoswipe: null as PhotoSwipe | null,

    list: [] as IPhoto[],
    days: new Map<number, IDay>(),
    dayIds: [] as number[],

    globalCount: 0,
    globalAnchor: -1,
    currIndex: -1,

    /** Timer to move to next photo */
    slideshowTimer: 0,
  }),

  mounted() {
    subscribe("files:sidebar:opened", this.handleAppSidebarOpen);
    subscribe("files:sidebar:closed", this.handleAppSidebarClose);
    subscribe("files:file:created", this.handleFileUpdated);
    subscribe("files:file:updated", this.handleFileUpdated);
  },

  beforeDestroy() {
    unsubscribe("files:sidebar:opened", this.handleAppSidebarOpen);
    unsubscribe("files:sidebar:closed", this.handleAppSidebarClose);
    unsubscribe("files:file:created", this.handleFileUpdated);
    unsubscribe("files:file:updated", this.handleFileUpdated);
  },

  computed: {
    /** Number of buttons to show inline */
    numInlineActions(): number {
      let base = 3;
      if (this.canShare) base++;
      if (this.canEdit) base++;

      if (globalThis.windowInnerWidth < 768) {
        return Math.min(base, 3);
      } else {
        return Math.min(base, 5);
      }
    },

    /** Route is public */
    routeIsPublic(): boolean {
      return this.$route.name?.endsWith("-share");
    },

    /** Route is album */
    routeIsAlbum(): boolean {
      return this.$route.name === "albums";
    },

    /** Get the currently open photo */
    currentPhoto(): IPhoto | null {
      if (!this.list.length || !this.photoswipe) {
        return null;
      }
      const idx = this.currIndex - this.globalAnchor;
      if (idx < 0 || idx >= this.list.length) {
        return null;
      }
      return this.list[idx];
    },

    /** Is the current slide a video */
    isVideo(): boolean {
      return Boolean(this.currentPhoto?.flag & this.c.FLAG_IS_VIDEO);
    },

    /** Show bottom bar info such as date taken */
    showBottomBar(): boolean {
      return (
        !this.isVideo &&
        this.fullyOpened &&
        Boolean(this.currentPhoto?.imageInfo)
      );
    },

    /** Get date taken string */
    currentDateTaken(): string | null {
      const date = this.currentPhoto?.imageInfo?.datetaken;
      if (!date) return null;
      return utils.getLongDateStr(new Date(date * 1000), false, true);
    },

    /** Get DAV download link for current photo */
    editorDownloadLink(): string | null {
      const filename = this.currentPhoto?.filename;
      return filename
        ? window.location.origin + getRootUrl() + `/remote.php/dav${filename}`
        : null;
    },

    /** Allow opening editor */
    canEdit(): boolean {
      return (
        this.currentPhoto?.mimetype?.startsWith("image/") &&
        !this.currentPhoto.liveid
      );
    },

    /** Does the browser support native share API */
    canShare(): boolean {
      return "share" in navigator && this.currentPhoto && !this.isVideo;
    },
  },

  methods: {
    deleted(photos: IPhoto[]) {
      this.$emit("deleted", photos);
    },

    fetchDay(dayId: number) {
      this.$emit("fetchDay", dayId);
    },

    updateLoading(delta: number) {
      this.$emit("updateLoading", delta);
    },

    /** Update the document title */
    updateTitle(photo: IPhoto | undefined) {
      if (!this.originalTitle) {
        this.originalTitle = document.title;
      }
      if (photo) {
        document.title = `${photo.basename} - ${globalThis.OCA.Theming?.name}`;
      } else {
        document.title = this.originalTitle;
        this.originalTitle = null;
      }
    },

    /** Event on file changed */
    handleFileUpdated({ fileid }: { fileid: number }) {
      if (this.currentPhoto && this.currentPhoto.fileid === fileid) {
        this.currentPhoto.etag += "_";
        this.currentPhoto.imageInfo = null;
        this.photoswipe.refreshSlideContent(this.currIndex);
      }
    },

    /** User interacted with the page with mouse */
    setUiVisible(evt: any) {
      clearTimeout(this.activityTimer);
      if (evt) {
        // If directly triggered, always update ui visibility
        // If triggered through a pointer event, only update if this is not
        // a touch event (i.e. a mouse move).
        // On touch devices, tapAction directly handles the ui visibility
        // through Photoswipe.
        const isPointer = evt instanceof PointerEvent;
        const isMouse = isPointer && evt.pointerType !== "touch";
        if (this.isOpen && (!isPointer || isMouse)) {
          this.photoswipe?.template?.classList.add("pswp--ui-visible");

          if (isMouse) {
            this.activityTimer = window.setTimeout(() => {
              if (this.isOpen) {
                this.photoswipe?.template?.classList.remove("pswp--ui-visible");
              }
            }, 2000);
          }
        }
      } else {
        this.photoswipe?.template?.classList.remove("pswp--ui-visible");
      }
    },

    /** Create the base photoswipe object */
    async createBase(args: PhotoSwipeOptions) {
      this.show = true;
      await this.$nextTick();

      this.photoswipe = new PhotoSwipe({
        counter: true,
        zoom: false,
        loop: false,
        wheelToZoom: true,
        bgOpacity: 1,
        appendToEl: this.$refs.inner as HTMLElement,
        preload: [2, 2],
        clickToCloseNonZoomable: false,
        bgClickAction: "toggle-controls",

        easing: "cubic-bezier(.49,.85,.55,1)",
        showHideAnimationType: "zoom",
        showAnimationDuration: 250,
        hideAnimationDuration: 250,

        closeTitle: this.t("memories", "Close"),
        arrowPrevTitle: this.t("memories", "Previous"),
        arrowNextTitle: this.t("memories", "Next"),
        getViewportSizeFn: () => {
          const isMobile = globalThis.windowInnerWidth < 768;
          const sidebarWidth =
            this.sidebarOpen && !isMobile ? this.sidebarWidth : 0;
          this.outerWidth = `calc(100vw - ${sidebarWidth}px)`;
          return {
            x: globalThis.windowInnerWidth - sidebarWidth,
            y: globalThis.windowInnerHeight,
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
            e.target.closest(".v-popper__popper") ||
            e.target.closest(".modal-mask")
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
        if (navElem) navElem.style.zIndex = "0";
      });
      this.photoswipe.on("openingAnimationStart", () => {
        this.isOpen = true;
        this.fullyOpened = false;
        if (this.sidebarOpen) {
          this.openSidebar();
        }
      });
      this.photoswipe.on("openingAnimationEnd", () => {
        this.fullyOpened = true;
      });
      this.photoswipe.on("close", () => {
        this.isOpen = false;
        this.fullyOpened = false;
        this.setUiVisible(false);
        this.hideSidebar();
        this.setRouteHash(undefined);
        this.updateTitle(undefined);
      });
      this.photoswipe.on("destroy", () => {
        document.body.classList.remove(klass);
        if (navElem) navElem.style.zIndex = "";

        // reset everything
        this.show = false;
        this.isOpen = false;
        this.fullyOpened = false;
        this.photoswipe = null;
        this.list = [];
        this.days.clear();
        this.dayIds = [];
        this.globalCount = 0;
        this.globalAnchor = -1;
        clearTimeout(this.slideshowTimer);
        this.slideshowTimer = 0;
      });

      // Update vue route for deep linking
      this.photoswipe.on("slideActivate", (e) => {
        this.currIndex = this.photoswipe.currIndex;
        const photo = e.slide?.data?.photo;
        this.setRouteHash(photo);
        this.updateTitle(photo);
        globalThis.currentViewerPhoto = photo;
      });

      // Show and hide controls
      this.photoswipe.on("uiRegister", (e) => {
        if (this.photoswipe?.template) {
          new MutationObserver((mutations) => {
            mutations.forEach((mutationRecord) => {
              this.showControls = (<HTMLElement>(
                mutationRecord.target
              ))?.classList.contains("pswp--ui-visible");
            });
          }).observe(this.photoswipe.template, {
            attributes: true,
            attributeFilter: ["class"],
          });
        }
      });

      // Video support
      new PsVideo(<any>this.photoswipe, {
        videoAttributes: { controls: "", playsinline: "", preload: "none" },
        autoplay: true,
        preventDragOffset: 40,
      });

      // Live photo support
      new PsLivePhoto(<any>this.photoswipe, {});

      // Image support
      new PsImage(<any>this.photoswipe);

      // Patch the close button to stop the slideshow
      const _close = this.photoswipe.close.bind(this.photoswipe);
      this.photoswipe.close = () => {
        if (this.slideshowTimer) {
          this.stopSlideshow();
        } else {
          _close();
        }
      };

      // Patch the next/prev buttons to reset slideshow timer
      const _next = this.photoswipe.next.bind(this.photoswipe);
      const _prev = this.photoswipe.prev.bind(this.photoswipe);
      this.photoswipe.next = () => {
        this.resetSlideshowTimer();
        _next();
      };
      this.photoswipe.prev = () => {
        this.resetSlideshowTimer();
        _prev();
      };

      return this.photoswipe;
    },

    /** Open using start photo and rows list */
    async open(anchorPhoto: IPhoto, rows?: IRow[]) {
      this.list = [...anchorPhoto.d.detail];
      let startIndex = -1;

      // Get days list and map
      for (const r of rows) {
        if (r.type === IRowType.HEAD) {
          if (this.TagDayIDValueSet.has(r.dayId)) continue;

          if (r.day.dayid == anchorPhoto.d.dayid) {
            startIndex = r.day.detail.indexOf(anchorPhoto);
            this.globalAnchor = this.globalCount;
          }

          this.globalCount += r.day.count;
          this.days.set(r.day.dayid, r.day);
          this.dayIds.push(r.day.dayid);
        }
      }

      // Create basic viewer
      await this.createBase({
        index: this.globalAnchor + startIndex,
      });

      // Lazy-generate item data.
      // Load the next two days in the timeline.
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
          this.thumbElem(photo)?.getAttribute("src") ||
          getPreviewUrl(photo, false, 256);

        // Get full image
        return {
          ...this.getItemData(photo),
          msrc: thumbSrc,
        };
      });

      // Get the thumbnail image
      this.photoswipe.addFilter("thumbEl", (thumbEl, data, index) => {
        const photo = this.list[index - this.globalAnchor];
        if (!photo || !photo.w || !photo.h) return thumbEl;
        return this.thumbElem(photo) || thumbEl;
      });

      this.photoswipe.on("slideActivate", (e) => {
        // Scroll to keep the thumbnail in view
        const thumb = this.thumbElem(e.slide.data?.photo);
        if (thumb && this.fullyOpened) {
          const rect = thumb.getBoundingClientRect();
          if (
            rect.bottom < 50 ||
            rect.top > globalThis.windowInnerHeight - 50
          ) {
            thumb.scrollIntoView({
              block: "center",
            });
          }
        }

        // Remove active class from others and add to this one
        document
          .querySelectorAll(".pswp__item")
          .forEach((el) => el.classList.remove("active"));
        e.slide.holderElement?.classList.add("active");
      });

      this.photoswipe.init();
    },

    /** Close the viewer */
    close() {
      this.photoswipe?.close();
    },

    /** Open with a static list of photos */
    async openStatic(photo: IPhoto, list: IPhoto[], thumbSize?: number) {
      this.list = list;
      await this.createBase({
        index: list.findIndex((p) => p.fileid === photo.fileid),
      });

      this.globalCount = list.length;
      this.globalAnchor = 0;

      this.photoswipe.addFilter("itemData", (itemData, index) => ({
        ...this.getItemData(this.list[index]),
        msrc: thumbSize ? getPreviewUrl(photo, false, thumbSize) : undefined,
      }));

      this.isOpen = true;
      this.photoswipe.init();
    },

    /** Get base data object */
    getItemData(photo: IPhoto) {
      const sw = Math.floor(screen.width * devicePixelRatio);
      const sh = Math.floor(screen.height * devicePixelRatio);
      let previewUrl = getPreviewUrl(photo, false, [sw, sh]);

      const isvideo = photo.flag & this.c.FLAG_IS_VIDEO;

      // Preview aren't animated
      if (isvideo || photo.mimetype === "image/gif") {
        previewUrl = getDownloadLink(photo);
      }

      // Get height and width
      let w = photo.w;
      let h = photo.h;

      if (isvideo && w && h) {
        // For videos, make sure the screen is filled up,
        // by scaling up the video by a maximum of 4x
        w *= 4;
        h *= 4;
      }

      // Lazy load the rest of EXIF data
      if (!photo.imageInfo) {
        axios.get(API.IMAGE_INFO(photo.fileid)).then((res) => {
          photo.imageInfo = res.data;
        });
      }

      return {
        src: previewUrl,
        width: w || undefined,
        height: h || undefined,
        thumbCropped: true,
        photo: photo,
        type: isvideo ? "video" : "image",
      };
    },

    /** Get element for thumbnail if it exists */
    thumbElem(photo: IPhoto): HTMLImageElement | undefined {
      if (!photo) return;
      const elems = Array.from(
        document.querySelectorAll(`.memories-thumb-${photo.key}`)
      );

      if (elems.length === 0) return;
      if (elems.length === 1) return elems[0] as HTMLImageElement;

      // Find if any element has the thumb-important class
      const important = elems.filter((e) =>
        e.classList.contains("thumb-important")
      );
      if (important.length > 0) return important[0] as HTMLImageElement;

      // Find element within 500px of the screen top
      let elem: HTMLImageElement;
      elems.forEach((e) => {
        const rect = e.getBoundingClientRect();
        if (rect.top > -500) {
          elem = e as HTMLImageElement;
        }
      });

      return elem;
    },

    /** Set the route hash to the given photo */
    setRouteHash(photo: IPhoto | undefined) {
      if (!photo) {
        if (!this.isOpen && this.$route.hash?.startsWith("#v")) {
          this.$router.back();

          // Ensure this does not have the hash, otherwise replace it
          if (this.$route.hash?.startsWith("#v")) {
            this.$router.replace({
              hash: "",
              query: this.$route.query,
            });
          }
        }
        return;
      }
      const hash = photo ? utils.getViewerHash(photo) : "";
      const route = {
        path: this.$route.path,
        query: this.$route.query,
        hash,
      };
      if (hash !== this.$route.hash) {
        if (this.$route.hash) {
          this.$router.replace(route);
        } else {
          this.$router.push(route);
        }
      }
    },

    openEditor() {
      // Only for JPEG for now
      if (!this.canEdit) return;
      this.editorOpen = true;
    },

    /** Share the current photo externally */
    async shareCurrent() {
      try {
        // Check navigator support
        if (!this.canShare) throw new Error("Share not supported");

        // Shre image data using navigator api
        const photo = this.currentPhoto;
        if (!photo) return;

        // No videos yet
        if (this.isVideo)
          throw new Error(
            this.t("memories", "Video sharing not supported yet")
          );

        // Get image blob
        const imgSrc = this.photoswipe.currSlide.data.src;
        const blob = await fetchImage(imgSrc);

        // Fix basename extension
        let basename = photo.basename;
        let targetExts = [];
        if (photo.mimetype === "image/png") {
          targetExts = ["png"];
        } else {
          targetExts = ["jpg", "jpeg"];
        }

        // Append extension if not found
        if (!targetExts.includes(basename.split(".").pop().toLowerCase())) {
          basename += "." + targetExts[0];
        }

        const data = {
          files: [
            new File([blob], basename, {
              type: blob.type,
            }),
          ],
          title: photo.basename,
          text: photo.basename,
        };

        if (!(<any>navigator).canShare(data)) {
          throw new Error(this.t("memories", "Cannot share this type of data"));
        }

        try {
          await navigator.share(data);
        } catch (e) {
          // Don't show this error because it's silly stuff
          // like "share canceled"
          console.error(e);
        }
      } catch (err) {
        console.error(err.name, err.message);
        showError(err.message);
      }
    },

    /** Key press events */
    keydown(e: KeyboardEvent) {
      if (
        e.key === "Delete" &&
        !this.routeIsPublic &&
        confirm(this.t("memories", "Are you sure you want to delete?"))
      ) {
        this.deleteCurrent();
      }
    },

    /** Delete this photo and refresh */
    async deleteCurrent() {
      let idx = this.photoswipe.currIndex - this.globalAnchor;
      const photo = this.list[idx];
      if (!photo) return;

      // Delete with WebDAV
      try {
        this.updateLoading(1);
        for await (const p of dav.deletePhotos([photo])) {
          if (!p[0]) return;
        }
      } finally {
        this.updateLoading(-1);
      }

      // Remove from main view
      this.deleted([photo]);

      // If this is the only photo, close viewer
      if (this.list.length === 1) {
        return this.close();
      }

      // If this is the last photo, move to the previous photo first
      // https://github.com/pulsejet/memories/issues/269
      if (idx === this.list.length - 1) {
        this.photoswipe.prev();

        // Some photos might lazy load, so recompute idx for the next element
        idx = this.photoswipe.currIndex + 1 - this.globalAnchor;
      }

      this.list.splice(idx, 1);
      this.globalCount--;
      for (let i = idx - 3; i <= idx + 3; i++) {
        this.photoswipe.refreshSlideContent(i + this.globalAnchor);
      }
    },

    /** Is the current photo a favorite */
    isFavorite() {
      const p = this.currentPhoto;
      if (!p) return false;
      return Boolean(p.flag & this.c.FLAG_IS_FAVORITE);
    },

    /** Favorite the current photo */
    async favoriteCurrent() {
      const photo = this.currentPhoto;
      const val = !this.isFavorite();
      try {
        this.updateLoading(1);
        for await (const p of dav.favoritePhotos([photo], val)) {
          if (!p[0]) return;
          this.$forceUpdate();
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
    },

    /** Download the current photo */
    async downloadCurrent() {
      const photo = this.currentPhoto;
      if (!photo) return;
      dav.downloadFilesByPhotos([photo]);
    },

    /** Download live part of current video */
    async downloadCurrentLiveVideo() {
      const photo = this.currentPhoto;
      if (!photo) return;
      window.location.href = utils.getLivePhotoVideoUrl(photo, false);
    },

    /** Open the sidebar */
    async openSidebar(photo?: IPhoto) {
      const fInfo = await dav.getFiles([photo || this.currentPhoto]);
      globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(true);
      globalThis.OCA.Files.Sidebar.setActiveTab("memories-metadata");
      globalThis.OCA.Files.Sidebar.open(fInfo[0].filename);
    },

    async updateSizeWithoutAnim() {
      const wasFullyOpened = this.fullyOpened;
      this.fullyOpened = false;
      this.photoswipe.updateSize();
      await new Promise((resolve) => setTimeout(resolve, 200));
      this.fullyOpened = wasFullyOpened;
    },

    handleAppSidebarOpen() {
      if (!(this.show && this.photoswipe)) return;

      const sidebar: HTMLElement = document.querySelector("aside.app-sidebar");
      if (sidebar) {
        this.sidebarWidth = sidebar.offsetWidth - 2;

        // Stop sidebar typing from leaking to viewer
        sidebar.addEventListener("keydown", (e) => {
          if (e.key.length === 1) e.stopPropagation();
        });
      }

      this.sidebarOpen = true;
      this.updateSizeWithoutAnim();
    },

    handleAppSidebarClose() {
      if (this.show && this.photoswipe && this.fullyOpened) {
        this.sidebarOpen = false;
        this.updateSizeWithoutAnim();
      }
    },

    /** Hide the sidebar, without marking it as closed */
    hideSidebar() {
      globalThis.OCA?.Files?.Sidebar?.close();
      globalThis.OCA?.Files?.Sidebar?.setFullScreenMode?.(false);
    },

    /** Close the sidebar */
    closeSidebar() {
      this.hideSidebar();
      this.sidebarOpen = false;
      this.photoswipe.updateSize();
    },

    /** Toggle the sidebar visibility */
    toggleSidebar() {
      if (this.sidebarOpen) {
        this.closeSidebar();
      } else {
        this.openSidebar();
      }
    },

    /**
     * Open the files app with the current file.
     */
    async viewInFolder() {
      if (this.currentPhoto) dav.viewInFolder(this.currentPhoto);
    },

    /**
     * Start a slideshow
     */
    async startSlideshow() {
      // Full screen the pswp element
      const pswp = this.photoswipe?.element;
      if (!pswp) return;
      pswp.requestFullscreen();

      // Hide controls
      this.setUiVisible(false);

      // Start slideshow
      this.slideshowTimer = window.setTimeout(
        this.slideshowTimerFired,
        SLIDESHOW_MS
      );
    },

    /**
     * Event of slideshow timer fire
     */
    slideshowTimerFired() {
      // Cancel if timer doesn't exist anymore
      // This can happen e.g. due to videos
      if (!this.slideshowTimer) return;

      // If this is a video, wait for it to finish
      if (this.isVideo) {
        // Get active video element
        const video: HTMLVideoElement = this.photoswipe?.element?.querySelector(
          ".pswp__item.active video"
        );

        // If no video tag is found by now, something likely went wrong. Just skip ahead.
        // Otherwise check if video is not ended yet
        if (video && video.currentTime < video.duration - 0.1) {
          // Wait for video to finish
          video.addEventListener("ended", this.slideshowTimerFired);
          return;
        }
      }

      this.photoswipe.next();
      // no need to set the timer again, since next
      // calls resetSlideshowTimer anyway
    },

    /**
     * Restart the slideshow timer
     */
    resetSlideshowTimer() {
      if (this.slideshowTimer) {
        window.clearTimeout(this.slideshowTimer);
        this.slideshowTimer = window.setTimeout(
          this.slideshowTimerFired,
          SLIDESHOW_MS
        );
      }
    },

    /**
     * Stop the slideshow
     */
    stopSlideshow() {
      window.clearTimeout(this.slideshowTimer);
      this.slideshowTimer = 0;

      // exit full screen
      if (document.fullscreenElement) {
        document.exitFullscreen();
      }
    },

    /**
     * Detect change in fullscreen
     */
    fullscreenChange() {
      if (!document.fullscreenElement) {
        this.stopSlideshow();
      }
    },

    /**
     * Edit Metadata for current photo
     */
    editMetadata() {
      globalThis.editMetadata([globalThis.currentViewerPhoto]);
    },
  },
});
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
  pointer-events: none;
  &.showControls {
    opacity: 1;
    pointer-events: auto;
  }
}

.bottom-bar {
  background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.3));
  width: 100%;
  padding: 10px;
  z-index: 100001;
  position: fixed;
  bottom: 0;
  left: 0;
  pointer-events: none;

  transition: opacity 0.2s ease-in-out;
  opacity: 0;
  &.showControls.showBottomBar {
    opacity: 1;
  }

  .exif {
    &.title {
      font-weight: bold;
      font-size: 0.9em;
    }
    &.description {
      margin-top: -2px;
      margin-bottom: 2px;
      font-size: 0.9em;
      max-width: 70vw;
      word-break: break-word;
      line-height: 1.2em;
    }
  }
}

.fullyOpened.slideshowTimer :deep .pswp__container {
  // Animate transitions
  // Disabled normally because this makes you sick if moving fast
  transition: transform 0.75s ease !important;
}

.inner,
.inner :deep .pswp {
  width: inherit;

  .pswp__top-bar {
    background: linear-gradient(0deg, transparent, rgba(0, 0, 0, 0.3));
  }
}

:deep .video-js .vjs-big-play-button {
  display: none;
}

:deep .plyr__volume {
  // Cannot be vertical yet :(
  @media (max-width: 768px) {
    display: none;
  }
}

:deep .pswp {
  contain: strict;

  .pswp__zoom-wrap {
    width: 100%;
    will-change: transform;
  }

  img.pswp__img {
    object-fit: contain;
    will-change: width, height;
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

  // Hide arrows on mobile
  @media (max-width: 768px) {
    .pswp__button--arrow {
      opacity: 0 !important;
    }
  }
}
</style>
