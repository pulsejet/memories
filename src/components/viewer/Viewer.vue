<template>
  <div
    class="memories_viewer outer"
    v-if="show"
    :class="{ fullyOpened, slideshowTimer }"
    :style="{ width: outerWidth }"
    @fullscreenchange="fullscreenChange"
    @keydown="keydown"
  >
    <ImageEditor v-if="editorOpen && currentPhoto" :photo="currentPhoto" @close="editorOpen = false" />

    <!-- Loading indicator -->
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <div
      class="inner"
      ref="inner"
      v-show="!editorOpen"
      @pointermove.passive="setUiVisible"
      @pointerdown.passive="setUiVisible"
    >
      <div class="top-bar" v-if="photoswipe" :class="{ showControls }">
        <NcActions :inline="numInlineActions" container=".memories_viewer .pswp">
          <NcActionButton
            v-if="canShare"
            :aria-label="t('memories', 'Share')"
            @click="shareCurrent"
            :close-after-click="true"
          >
            {{ t('memories', 'Share') }}
            <template #icon> <ShareIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsAlbums && canDelete"
            :aria-label="t('memories', 'Delete')"
            @click="deleteCurrent"
            :close-after-click="true"
          >
            {{ t('memories', 'Delete') }}
            <template #icon> <DeleteIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="routeIsAlbums"
            :aria-label="t('memories', 'Remove from album')"
            @click="deleteCurrent"
            :close-after-click="true"
          >
            {{ t('memories', 'Remove from album') }}
            <template #icon> <AlbumRemoveIcon :size="24" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="isLivePhoto"
            :aria-label="t('memories', 'Play Live Photo')"
            @click="playLivePhoto"
            :close-after-click="true"
          >
            {{ t('memories', 'Play Live Photo') }}
            <template #icon>
              <LivePhotoIcon :size="24" :playing="liveState.playing" :spin="liveState.waiting" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic && !isLocal"
            :aria-label="t('memories', 'Favorite')"
            @click="favoriteCurrent"
            :close-after-click="true"
          >
            {{ t('memories', 'Favorite') }}
            <template #icon>
              <StarIcon v-if="isFavorite()" :size="24" />
              <StarOutlineIcon v-else :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton :aria-label="t('memories', 'Info')" @click="toggleSidebar" :close-after-click="true">
            {{ t('memories', 'Info') }}
            <template #icon>
              <InfoIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="canEdit && !isVideo"
            :aria-label="t('memories', 'Edit')"
            @click="openEditor"
            :close-after-click="true"
          >
            {{ t('memories', 'Edit') }}
            <template #icon>
              <TuneIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Download')"
            @click="downloadCurrent"
            :close-after-click="true"
            v-if="!initstate.noDownload && !isLocal"
          >
            {{ t('memories', 'Download') }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="!initstate.noDownload && currentPhoto?.liveid"
            :aria-label="t('memories', 'Download Video')"
            @click="downloadCurrentLiveVideo"
            :close-after-click="true"
          >
            {{ t('memories', 'Download Video') }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-for="raw of stackedRaw"
            :aria-label="t('memories', 'Download {ext}', { ext: raw.extension })"
            @click="downloadByFileId(raw.fileid)"
            :close-after-click="true"
            :key="raw.fileid"
          >
            {{ t('memories', 'Download {ext}', { ext: raw.extension }) }}
            <template #icon>
              <DownloadIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            v-if="!routeIsPublic && !routeIsAlbums && !isLocal"
            :aria-label="t('memories', 'View in folder')"
            @click="viewInFolder"
            :close-after-click="true"
          >
            {{ t('memories', 'View in folder') }}
            <template #icon>
              <OpenInNewIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Slideshow')"
            v-if="globalCount > 1"
            @click="startSlideshow"
            :close-after-click="true"
          >
            {{ t('memories', 'Slideshow') }}
            <template #icon>
              <SlideshowIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Edit metadata')"
            v-if="canEdit"
            @click="editMetadata()"
            :close-after-click="true"
          >
            {{ t('memories', 'Edit metadata') }}
            <template #icon>
              <EditFileIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Rotate / Flip')"
            v-if="canEdit && !isVideo"
            @click="editMetadata([5])"
            :close-after-click="true"
          >
            {{ t('memories', 'Rotate / Flip') }}
            <template #icon>
              <RotateLeftIcon :size="24" />
            </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Add to album')"
            v-if="config.albums_enabled && !isLocal && !routeIsPublic && canShare && currentPhoto?.imageInfo?.filename"
            @click="updateAlbums"
            :close-after-click="true"
          >
            {{ t('memories', 'Add to album') }}
            <template #icon>
              <AlbumIcon :size="24" />
            </template>
          </NcActionButton>
        </NcActions>
      </div>

      <div class="bottom-bar" v-if="photoswipe" :class="{ showControls, showBottomBar }">
        <div class="exif title" v-if="currentPhoto?.imageInfo?.exif?.Title">
          {{ currentPhoto.imageInfo.exif.Title }}
        </div>
        <div class="exif description" v-if="currentPhoto?.imageInfo?.exif?.Description">
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
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';
import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import ImageEditor from './ImageEditor.vue';
import PhotoSwipe, { type PhotoSwipeOptions } from 'photoswipe';
import 'photoswipe/style.css';
import PsImage from './PsImage';
import PsVideo from './PsVideo';
import PsLivePhoto from './PsLivePhoto';

import type { IImageInfo, IPhoto, TimelineState } from '@typings';
import type { PsContent } from './types';

import LivePhotoIcon from '@components/icons/LivePhoto.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import InfoIcon from 'vue-material-design-icons/InformationOutline.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import SlideshowIcon from 'vue-material-design-icons/PlayBox.vue';
import EditFileIcon from 'vue-material-design-icons/FileEdit.vue';
import AlbumRemoveIcon from 'vue-material-design-icons/BookRemove.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import RotateLeftIcon from 'vue-material-design-icons/RotateLeft.vue';

const SLIDESHOW_MS = 5000;
const SIDEBAR_DEBOUNCE_MS = 350;
const BODY_HAS_VIEWER = 'has-viewer';
const BODY_VIEWER_VIDEO = 'viewer-video';
const BODY_VIEWER_FULLY_OPENED = 'viewer-fully-opened';

export default defineComponent({
  name: 'Viewer',
  components: {
    NcActions,
    NcActionButton,
    ImageEditor,
    LivePhotoIcon,
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
    AlbumIcon,
    RotateLeftIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    loading: 0,
    isOpen: false,
    originalTitle: null as string | null,
    editorOpen: false,
    editorSrc: '',

    show: false,
    showControls: false,
    fullyOpened: false,
    sidebarOpen: false,
    sidebarWidth: 400,
    outerWidth: '100vw',

    /** User interaction detection */
    activityTimer: 0,

    /** Base dialog */
    photoswipe: null as PhotoSwipe | null,
    psVideo: null as PsVideo | null,
    psImage: null as PsImage | null,
    psLivePhoto: null as PsLivePhoto | null,

    /** Live photo state */
    liveState: {
      playing: false,
      waiting: false,
    },

    /** List globals */
    list: [] as IPhoto[],
    globalCount: 0,
    globalAnchor: -1,
    currIndex: -1,

    /** Timer to move to next photo */
    slideshowTimer: 0,
    /** Timer to debounce changes to sidebar */
    sidebarUpdateTimer: 0,

    /** Photo keys for which an imageInfo request is currently ongoing */
    imageInfoLoading: new Set<string>(),
  }),

  mounted() {
    utils.bus.on('memories:sidebar:opened', this.handleAppSidebarOpen);
    utils.bus.on('memories:sidebar:closed', this.handleAppSidebarClose);
    utils.bus.on('files:file:created', this.handleFileUpdated);
    utils.bus.on('files:file:updated', this.handleFileUpdated);
    utils.bus.on('memories:window:resize', this.handleWindowResize);
    utils.bus.on('memories:fragment:pop:viewer', this.close);

    // The viewer is a singleton
    const self = this;
    _m.viewer = {
      open: this.setFragment.bind(this) as typeof this.setFragment,
      openDynamic: this.openDynamic.bind(this) as typeof this.openDynamic,
      openStatic: this.openStatic.bind(this) as typeof this.openStatic,
      close: this.close.bind(this) as typeof this.close,
      get isOpen() {
        return self.isOpen;
      },
      get currentPhoto() {
        return self.currentPhoto;
      },
    };
  },

  beforeDestroy() {
    utils.bus.off('memories:sidebar:opened', this.handleAppSidebarOpen);
    utils.bus.off('memories:sidebar:closed', this.handleAppSidebarClose);
    utils.bus.off('files:file:created', this.handleFileUpdated);
    utils.bus.off('files:file:updated', this.handleFileUpdated);
    utils.bus.off('memories:window:resize', this.handleWindowResize);
    utils.bus.off('memories:fragment:pop:viewer', this.close);
  },

  computed: {
    refs() {
      return this.$refs as {
        inner: HTMLDivElement;
      };
    },

    /** Number of buttons to show inline */
    numInlineActions(): number {
      let base = 3;
      if (this.canShare) base++;
      if (this.canEdit) base++;

      if (_m.window.innerWidth < 768) {
        return Math.min(base, 3);
      } else {
        return Math.min(base, 5);
      }
    },

    /** Get the currently open photo */
    currentPhoto(): IPhoto | null {
      if (!this.list.length || !this.photoswipe) return null;

      const idx = this.currIndex - this.globalAnchor;
      if (idx < 0 || idx >= this.list.length) return null;

      return this.list[idx];
    },

    /** Is the current slide a video */
    isVideo(): boolean {
      return Boolean((this.currentPhoto?.flag ?? 0) & this.c.FLAG_IS_VIDEO);
    },

    /** Is the current slide a live photo */
    isLivePhoto(): boolean {
      return Boolean(this.currentPhoto?.liveid);
    },

    /** Is the current slide a local photo */
    isLocal(): boolean {
      return utils.isLocalPhoto(this.currentPhoto!);
    },

    /** Show bottom bar info such as date taken */
    showBottomBar(): boolean {
      return !this.isVideo && this.fullyOpened && Boolean(this.currentPhoto?.imageInfo);
    },

    /** Allow closing the viewer */
    allowClose(): boolean {
      return !this.editorOpen && !dav.isSingleItem();
    },

    /** Get date taken string */
    currentDateTaken(): string | null {
      const date = this.currentPhoto?.imageInfo?.datetaken;
      if (!date) return null;
      return utils.getLongDateStr(new Date(date * 1000), false, true);
    },

    /** Show edit buttons */
    canEdit(): boolean {
      return this.currentPhoto?.imageInfo?.permissions?.includes('U') ?? false;
    },

    /** Show delete button */
    canDelete(): boolean {
      return this.currentPhoto?.imageInfo?.permissions?.includes('D') ?? false;
    },

    /** Show share button and add to album button */
    canShare(): boolean {
      return Boolean(this.currentPhoto);
    },

    /** Stacked RAW photos */
    stackedRaw(): { extension: string; fileid: number }[] {
      const photo = this.currentPhoto;
      if (!photo || !photo.stackraw?.length) return [];

      return photo.stackraw.map((raw) => ({
        extension: (raw.basename?.split('.').pop() ?? '?').toUpperCase(),
        fileid: raw.fileid,
      }));
    },
  },

  watch: {
    fullyOpened(val) {
      document.body.classList.toggle(BODY_VIEWER_FULLY_OPENED, val);
    },
  },

  methods: {
    updateLoading(delta: number) {
      this.loading += delta;
    },

    /** Update the document title */
    updateTitle(photo: IPhoto | undefined) {
      this.originalTitle ||= document.title;
      if (photo) {
        document.title = `${photo.basename} - ${this.originalTitle}`;
      } else {
        document.title = this.originalTitle;
        this.originalTitle = null;
      }
    },

    /** Event on file changed */
    handleFileUpdated({ fileid }: { fileid: number }) {
      const photo = this.currentPhoto;
      const isvideo = photo && photo.flag & this.c.FLAG_IS_VIDEO;
      if (photo && !isvideo && photo.fileid === fileid) {
        this.photoswipe?.refreshSlideContent(this.currIndex);
      }
    },

    /** User interacted with the page with mouse */
    setUiVisible(event: PointerEvent | false) {
      clearTimeout(this.activityTimer);
      if (event) {
        // If directly triggered, always update ui visibility
        // If triggered through a pointer event, only update if this is not
        // a touch event (i.e. a mouse move).
        // On touch devices, tapAction directly handles the ui visibility
        // through Photoswipe.
        const isPointer = event instanceof PointerEvent;
        const isMouse = isPointer && event.pointerType !== 'touch';
        if (this.isOpen && (!isPointer || isMouse)) {
          this.photoswipe?.template?.classList.add('pswp--ui-visible');

          if (isMouse) {
            this.activityTimer = window.setTimeout(() => {
              if (this.isOpen) {
                this.photoswipe?.template?.classList.remove('pswp--ui-visible');
              }
            }, 2000);
          }
        }
      } else {
        this.photoswipe?.template?.classList.remove('pswp--ui-visible');
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
        appendToEl: this.refs.inner!,
        preload: [2, 2],
        bgClickAction: 'toggle-controls',

        clickToCloseNonZoomable: false,
        pinchToClose: this.allowClose,
        closeOnVerticalDrag: this.allowClose,

        easing: 'cubic-bezier(.49,.85,.55,1)',
        showHideAnimationType: 'zoom',
        showAnimationDuration: 250,
        hideAnimationDuration: 250,

        closeTitle: this.t('memories', 'Close'),
        arrowPrevTitle: this.t('memories', 'Previous'),
        arrowNextTitle: this.t('memories', 'Next'),
        getViewportSizeFn: () => {
          // Ignore the sidebar if mobile or fullscreen
          const isFullscreen = Boolean(document.fullscreenElement);
          const use = this.sidebarOpen && !utils.isMobile() && !isFullscreen;

          // Calculate the sidebar width to use and outer width
          const sidebarWidth = use ? _m.sidebar.getWidth() : 0;
          this.outerWidth = `calc(100vw - ${sidebarWidth}px)`;

          return {
            x: _m.window.innerWidth - sidebarWidth,
            y: _m.window.innerHeight,
          };
        },
        ...args,
      });

      // Debugging only
      _m.viewer.photoswipe = this.photoswipe;

      // Monkey patch for focus trapping in sidebar
      const psKeyboard = this.photoswipe.keyboard as any;
      const _onFocusIn = psKeyboard['_onFocusIn'];
      console.assert(_onFocusIn, 'Missing _onFocusIn for monkey patch');
      psKeyboard['_onFocusIn'] = (e: FocusEvent) => {
        if (
          e.target instanceof HTMLElement &&
          e.target.closest(['#app-sidebar-vue', '.v-popper__popper', '.modal-mask', '.oc-dialog'].join(','))
        ) {
          return;
        }
        _onFocusIn.call(this.photoswipe!.keyboard, e);
      };

      // Refresh sidebar on change
      this.photoswipe.on('change', () => {
        if (this.sidebarOpen) {
          this.openSidebar();
        }
      });

      // Make sure buttons are styled properly
      this.photoswipe.addFilter('uiElement', (element, data) => {
        // add button-vue class if button
        if (element.classList.contains('pswp__button')) {
          element.classList.add('button-vue');
        }
        return element;
      });

      // Total number of photos in this view
      this.photoswipe.addFilter('numItems', () => this.globalCount);

      // Put viewer over everything else
      const navElem = document.getElementById('app-navigation-vue');
      this.photoswipe.on('beforeOpen', () => {
        document.body.classList.add(BODY_HAS_VIEWER);
        if (navElem) navElem.style.zIndex = '0';
      });
      this.photoswipe.on('openingAnimationStart', () => {
        this.isOpen = true;
        this.fullyOpened = false;
        if (this.sidebarOpen) {
          this.openSidebar();
        }
        nativex.setTheme('#000000', true); // viewer is always dark
      });
      this.photoswipe.on('openingAnimationEnd', () => {
        this.fullyOpened = true;
      });
      this.photoswipe.on('close', () => {
        this.isOpen = false;
        this.fullyOpened = false;
        this.setUiVisible(false);
        this.hideSidebar();
        this.setFragment(null);
        this.updateTitle(undefined);
        nativex.setTheme(); // reset
        document.body.classList.remove(BODY_VIEWER_VIDEO);
      });
      this.photoswipe.on('destroy', () => {
        document.body.classList.remove(BODY_HAS_VIEWER);
        if (navElem) navElem.style.zIndex = '';

        // reset everything
        this.show = false;
        this.isOpen = false;
        this.fullyOpened = false;
        this.editorOpen = false;
        this.photoswipe = null;
        this.list = [];
        this.globalCount = 0;
        this.globalAnchor = -1;
        clearTimeout(this.slideshowTimer);
        this.slideshowTimer = 0;
      });

      // Update vue route for deep linking
      this.photoswipe.on('slideActivate', (e) => {
        this.currIndex = this.photoswipe!.currIndex;
        const photo = e.slide?.data?.photo;
        this.setFragment(photo);
        this.updateTitle(photo);
      });

      // Show and hide controls
      this.photoswipe.on('uiRegister', (e) => {
        if (this.photoswipe?.template) {
          new MutationObserver((mutations) => {
            mutations.forEach((mutationRecord) => {
              this.showControls = (<HTMLElement>mutationRecord.target)?.classList.contains('pswp--ui-visible');
            });
          }).observe(this.photoswipe.template, {
            attributes: true,
            attributeFilter: ['class'],
          });
        }
      });

      // Video support
      this.psVideo = new PsVideo(<any>this.photoswipe, {
        preventDragOffset: 40,
      });

      // Image support
      this.psImage = new PsImage(<any>this.photoswipe);

      // Live Photo support
      this.psLivePhoto = new PsLivePhoto(<any>this.photoswipe, <any>this.psImage, this.liveState);

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

    /** Set the route hash to the given photo */
    setFragment(photo: IPhoto | null) {
      // Add or update fragment
      if (photo) {
        return utils.fragment.push(utils.fragment.types.viewer, String(photo.dayid), photo.key!);
      }

      // Remove fragment if closed
      if (!this.isOpen) {
        return utils.fragment.pop(utils.fragment.types.viewer);
      }
    },

    /** Open using start photo and rows list */
    async openDynamic(anchorPhoto: IPhoto, timeline: TimelineState) {
      const detail = anchorPhoto.d?.detail;
      if (!detail?.length) {
        console.error('Attempted to open viewer with no detail list!');
        return;
      }

      // Helper to compute the global anchor and count
      // Anchor is the global index of the first list item
      const computeGlobals = () => {
        const dayIds = new Array<number>(timeline.heads.size);
        let count = 0;
        let anchor = -1;
        let iter = 0;

        // Iterate the heads to get the anchor and count.
        const anchorDayId = this.list[0].dayid;
        for (const [dayId, row] of timeline.heads) {
          // Compute this hear so we can do single pass
          dayIds[iter++] = dayId;

          // Get the global index of the anchor
          if (dayId == anchorDayId) {
            anchor = count;
          }

          // Add count of this day
          count += row.day.count;
        }

        return { dayIds, anchor, count };
      };

      // Create initial list
      this.list = [...detail];

      // Compute globals
      let globals = computeGlobals();
      this.globalAnchor = globals.anchor;
      this.globalCount = globals.count;

      // Create basic viewer
      const startIndex = detail.indexOf(anchorPhoto);
      const photoswipe = await this.createBase({
        index: this.globalAnchor + startIndex,
      });

      // Lazy-generate item data. This is called for each item in the list
      photoswipe.addFilter('itemData', (itemData, index) => {
        if (!this.list) return {};
        const { dayIds } = globals;

        // Once every cycle, refresh the globals
        utils.setRenewingTimeout(
          this,
          '_odgt',
          () => {
            if (!this.photoswipe) return;
            globals = computeGlobals();
            let goTo: null | number = null; // final index of photoswipe

            // If the anchor shifts to the left, we need to shift the index
            // by the same amount. This happens synchronously, so update first.
            if (globals.anchor < this.globalAnchor) {
              goTo = this.photoswipe.currIndex - (this.globalAnchor - globals.anchor);
            }

            // Update the global anchor and count
            this.globalCount = globals.count;
            this.globalAnchor = globals.anchor;

            // Go to the new index if needed
            if (goTo === null) {
              // no change
            } else if (this.photoswipe.currIndex >= this.globalCount) {
              this.photoswipe.goTo(this.globalCount - 1);
            } else if (goTo < 0) {
              this.photoswipe.goTo(0);
            } else {
              this.photoswipe.goTo(goTo);
            }
          },
          0,
        );

        // Get photo object from list
        let idx = index - this.globalAnchor;
        if (idx < 0) {
          // Load previous day
          const firstDayId = this.list[0].dayid;
          const firstDayIdx = utils.binarySearch(dayIds, firstDayId);
          if (firstDayIdx === 0) {
            // No previous day
            return {};
          }
          const prevDayId = dayIds[firstDayIdx - 1];
          const prevDay = timeline.heads.get(prevDayId)?.day;
          if (!prevDay?.detail) {
            console.error('[BUG] No detail for previous day');
            return {};
          }
          this.list.unshift(...prevDay.detail);
          this.globalAnchor -= prevDay.count;
        } else if (idx >= this.list.length) {
          // Load next day
          const lastDayId = this.list[this.list.length - 1].dayid;
          const lastDayIdx = utils.binarySearch(dayIds, lastDayId);
          if (lastDayIdx === dayIds.length - 1) {
            // No next day
            return {};
          }
          const nextDayId = dayIds[lastDayIdx + 1];
          const nextDay = timeline.heads.get(nextDayId)?.day;
          if (!nextDay?.detail) {
            console.error('[BUG] No detail for next day');
            return {};
          }
          this.list.push(...nextDay.detail);
        }

        idx = index - this.globalAnchor;
        const photo = this.list[idx];

        // Something went really wrong
        console.assert(photo, 'Missing photo for index', index, 'and global anchor', this.globalAnchor);
        if (!photo) return {};

        // Get index of current day in dayIds lisst
        const dayIdx = utils.binarySearch(dayIds, photo.dayid);

        // Preload next and previous 3 days
        for (let idx = dayIdx - 3; idx <= dayIdx + 3; idx++) {
          if (idx < 0 || idx >= dayIds.length || idx === dayIdx) continue;

          const day = timeline.heads.get(dayIds[idx])?.day;
          if (day && !day?.detail) {
            // duplicate requests are skipped by Timeline
            utils.bus.emit('memories:timeline:fetch-day', day.dayid);
          }
        }

        const data = this.getItemData(photo);
        data.msrc = this.thumbElem(photo)?.getAttribute('src') ?? utils.getPreviewUrl({ photo, msize: 256 });
        return data;
      });

      // Get the thumbnail image
      photoswipe.addFilter('thumbEl', (thumbEl, data, index) => {
        const photo = this.list[index - this.globalAnchor];
        if (!photo || !photo.w || !photo.h) return thumbEl as HTMLElement;
        return this.thumbElem(photo) ?? (thumbEl as HTMLElement); // bug in PhotoSwipe types
      });

      photoswipe.on('slideActivate', (e) => {
        // Scroll to keep the thumbnail in view
        const thumb = this.thumbElem(e.slide.data?.photo);
        if (thumb && this.fullyOpened) {
          const rect = thumb.getBoundingClientRect();
          if (rect.bottom < 50 || rect.top > _m.window.innerHeight - 50) {
            thumb.scrollIntoView({ block: 'center' });
          }
        }

        // Remove active class from others and add to this one
        photoswipe.element?.querySelectorAll('.pswp__item').forEach((el) => el.classList.remove('active'));
        e.slide.holderElement?.classList.add('active');

        // Add type class to body
        document.body.classList.toggle(BODY_VIEWER_VIDEO, !!(e.slide.data?.photo?.flag & this.c.FLAG_IS_VIDEO));
      });

      photoswipe.init();
    },

    /** Close the viewer */
    close() {
      if (!this.isOpen) return;
      this.photoswipe?.close();
    },

    /** Open with a static list of photos */
    async openStatic(photo: IPhoto, list: IPhoto[], thumbSize?: 256 | 512) {
      this.list = list;
      const photoswipe = await this.createBase({
        index: list.findIndex((p) => p.fileid === photo.fileid),
      });

      this.globalCount = list.length;
      this.globalAnchor = 0;

      photoswipe.addFilter('itemData', (itemData, index) => ({
        ...this.getItemData(this.list[index]),
        msrc: thumbSize ? utils.getPreviewUrl({ photo, msize: thumbSize }) : undefined,
      }));

      this.isOpen = true;
      photoswipe.init();
    },

    /** Get base data object */
    getItemData(photo: IPhoto): PsContent['data'] {
      let previewUrl = utils.getPreviewUrl({ photo, size: 'screen' });
      const isvideo = photo.flag & this.c.FLAG_IS_VIDEO;

      // Preview aren't animated
      if (isvideo || photo.mimetype === 'image/gif') {
        previewUrl = dav.getDownloadLink(photo);
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
      this.loadMetadata(photo);

      // Get full image URL
      const highSrc: string[] = [];
      if (!isvideo) {
        // Try local file if NativeX is available
        if (photo.auid && nativex.has()) {
          highSrc.push(nativex.NAPI.IMAGE_FULL(photo.auid));
        }

        // Decodable full resolution image
        highSrc.push(API.IMAGE_DECODABLE(photo.fileid, photo.etag));
      }

      // Condition of loading full resolution image
      const highSrcCond = this.config.high_res_cond || this.config.high_res_cond_default || 'zoom';

      return {
        src: previewUrl,
        highSrc: highSrc,
        highSrcCond: highSrcCond,
        width: w || undefined,
        height: h || undefined,
        thumbCropped: true,
        photo: photo,
        type: isvideo ? 'video' : 'image',
      };
    },

    /** Get element for thumbnail if it exists */
    thumbElem(photo: IPhoto): HTMLImageElement | undefined {
      if (!photo) return;
      const elems = Array.from(document.querySelectorAll(`.memories-thumb-${photo.key}`));

      if (elems.length === 0) return;
      if (elems.length === 1) return elems[0] as HTMLImageElement;

      // Find if any element has the thumb-important class
      const important = elems.filter((e) => e.classList.contains('thumb-important'));
      if (important.length > 0) return important[0] as HTMLImageElement;

      // Find element within 500px of the screen top
      let elem: HTMLImageElement | undefined;
      elems.forEach((e) => {
        const rect = e.getBoundingClientRect();
        if (rect.top > -500) {
          elem = e as HTMLImageElement;
        }
      });

      return elem;
    },

    /**
     * Load the metadata (image info) for a photo asynchronously
     */
    async loadMetadata(photo: IPhoto) {
      // Check if already loaded
      if (photo.imageInfo) return;

      // Check if already loading
      const key = photo.key ?? photo.fileid.toString();
      if (this.imageInfoLoading.has(key)) return;

      // Mark as loading
      this.imageInfoLoading.add(key);

      try {
        const res = await axios.get<IImageInfo>(utils.getImageInfoUrl(photo));
        photo.imageInfo = res.data;

        // Update params in photo object
        photo.w = res.data.w;
        photo.h = res.data.h;
        photo.basename = res.data.basename;
        photo.mimetype = res.data.mimetype;
      } finally {
        // Allow another chance in case this failed
        this.imageInfoLoading.delete(key);
      }
    },

    async openEditor() {
      // Only for JPEG for now
      if (!this.canEdit) return;

      // Prevent editing Live Photos
      if (this.isLivePhoto) {
        showError(this.t('memories', 'Editing is currently disabled for Live Photos'));
        return;
      }

      // Open editor
      this.editorOpen = true;
    },

    /** Share the current photo externally */
    async shareCurrent() {
      _m.modals.sharePhoto(this.currentPhoto!);
    },

    /** Key press events */
    keydown(e: KeyboardEvent) {
      if (e.key === 'Delete') {
        this.deleteCurrent();
      }
    },

    /** Delete this photo and refresh */
    async deleteCurrent() {
      if (this.routeIsPublic) return;

      let idx = this.photoswipe!.currIndex - this.globalAnchor;
      const photo = this.list[idx];
      if (!photo) return;

      // Delete with WebDAV
      try {
        this.updateLoading(1);
        for await (const p of dav.deletePhotos([photo])) {
          if (!p[0]) return;
        }
      } catch {
        return;
      } finally {
        this.updateLoading(-1);
      }

      // Remove from main view
      utils.bus.emit('memories:timeline:deleted', [photo]);

      // If this is the only photo, close viewer
      if (this.list.length === 1) {
        return this.close();
      }

      // If this is the last photo, move to the previous photo first
      // https://github.com/pulsejet/memories/issues/269
      if (idx === this.list.length - 1) {
        this.photoswipe!.prev();

        // Some photos might lazy load, so recompute idx for the next element
        idx = this.photoswipe!.currIndex + 1 - this.globalAnchor;
      }

      this.list.splice(idx, 1);
      this.globalCount--;
      for (let i = idx - 3; i <= idx + 3; i++) {
        this.photoswipe!.refreshSlideContent(i + this.globalAnchor);
      }
    },

    /** Play the current live photo */
    playLivePhoto() {
      this.psLivePhoto?.play(this.photoswipe!.currSlide!.content as PsContent);
    },

    /** Is the current photo a favorite */
    isFavorite() {
      const p = this.currentPhoto;
      if (!p) return false;
      return Boolean(p.flag & this.c.FLAG_IS_FAVORITE);
    },

    /** Favorite the current photo */
    async favoriteCurrent() {
      const photo = this.currentPhoto!;
      const val = !this.isFavorite();
      try {
        this.updateLoading(1);
        for await (const p of dav.favoritePhotos([photo], val)) {
          // Do nothing
        }
      } finally {
        this.updateLoading(-1);
      }
      this.$forceUpdate();
    },

    /** Download a file by file ID */
    async downloadByFileId(fileId: number) {
      dav.downloadFiles([fileId]);
    },

    /** Download the current photo */
    async downloadCurrent() {
      const photo = this.currentPhoto;
      if (!photo) return;
      this.downloadByFileId(photo.fileid);
    },

    /** Download live part of current video */
    async downloadCurrentLiveVideo() {
      const photo = this.currentPhoto;
      if (!photo) return;
      window.location.href = utils.getLivePhotoVideoUrl(photo, false);
    },

    /**
     * Open the sidebar.
     *
     * Calls to this function are debounced to prevent too many updates
     * to the sidebar while the user is scrolling through photos.
     */
    async openSidebar() {
      const photo = this.currentPhoto;
      if (!photo) return;
      const abort = () => !this.isOpen || photo !== this.currentPhoto;

      // Invalidate currently open metadata
      _m.sidebar.invalidateUnless(photo.fileid);

      // Update the sidebar, first call immediate
      utils.setRenewingTimeout(
        this,
        '_sidebarUpdateTimer',
        async () => {
          if (abort()) return;

          _m.sidebar.setTab('memories-metadata');
          if (this.routeIsPublic || this.isLocal) {
            _m.sidebar.open(photo);
          } else {
            const fileInfo = (await dav.getFiles([photo]))[0];
            if (!fileInfo || abort()) return;

            // get attributes
            const filename = fileInfo?.filename;
            const useNative = fileInfo?.originalFilename?.startsWith('/files/');

            // open sidebar
            _m.sidebar.open(photo, filename, useNative);
          }
        },
        SIDEBAR_DEBOUNCE_MS,
        true,
      );
    },

    handleAppSidebarOpen() {
      if (this.show && this.photoswipe) {
        this.sidebarOpen = true;
        this.photoswipe.updateSize();
      }
    },

    handleAppSidebarClose() {
      if (this.show && this.photoswipe && this.fullyOpened) {
        this.sidebarOpen = false;
        this.photoswipe.updateSize();
      }
    },

    handleWindowResize() {
      this.show && this.photoswipe?.updateSize();
    },

    /** Hide the sidebar, without marking it as closed */
    hideSidebar() {
      _m.sidebar.close();
    },

    /** Close the sidebar */
    closeSidebar() {
      this.hideSidebar();
      this.sidebarOpen = false;
      this.photoswipe?.updateSize();
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
      dav.viewInFolder(this.currentPhoto!);
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
      this.slideshowTimer = window.setTimeout(this.slideshowTimerFired, SLIDESHOW_MS);
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
        const video = this.photoswipe?.element?.querySelector<HTMLVideoElement>('.pswp__item.active video');

        // If no video tag is found by now, something likely went wrong. Just skip ahead.
        // Otherwise check if video is not ended yet
        if (video && video.currentTime < video.duration - 0.1) {
          // Wait for video to finish
          video.addEventListener('ended', this.slideshowTimerFired);
          return;
        }
      }

      this.photoswipe?.next();
      // no need to set the timer again, since next
      // calls resetSlideshowTimer anyway
    },

    /**
     * Restart the slideshow timer
     */
    resetSlideshowTimer() {
      if (this.slideshowTimer) {
        window.clearTimeout(this.slideshowTimer);
        this.slideshowTimer = window.setTimeout(this.slideshowTimerFired, SLIDESHOW_MS);
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
      this.photoswipe?.updateSize();
    },

    /**
     * Edit metadata for current photo
     */
    editMetadata(sections?: number[]) {
      _m.modals.editMetadata([this.currentPhoto!], sections);
    },

    /**
     * Update album selection for current photo
     */
    updateAlbums() {
      _m.modals.updateAlbums([this.currentPhoto!]);
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  z-index: 2020;
  width: 100vw;
  height: 100vh;
  position: fixed;
  top: 0;
  left: 0;
  overflow: hidden;
  color: white;

  > .loading-icon {
    z-index: 1000000;
  }
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
  width: inherit;
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
      max-width: 90%;
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

  .video-container {
    &.error {
      color: red;
      display: flex;
      align-items: center;
      justify-content: center;
    }
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
  }

  img.pswp__img {
    object-fit: contain;
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

  // Prevent the popper from overlapping with the sidebar
  > div > .v-popper__wrapper {
    overflow: visible !important;
    > .v-popper__inner {
      transform: translateX(-20px);
    }
  }
}
</style>
