<template>
  <div
    v-if="show"
    ref="outer"
    class="memories-viewer outer remove-gap"
    :class="{
      'fully-opened': fullyOpened,
      'is-video': isVideo,
      'is-slideshow': !!slideshowTimer,
      'force-metadata': !!slideshowTimer && config.metadata_in_slideshow,
    }"
    @fullscreenchange="fullscreenChange"
  >
    <ImageEditor v-if="editorOpen && currentPhoto" :photo="currentPhoto" @close="editorOpen = false" />

    <!-- Loading indicator -->
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <div
      ref="inner"
      class="inner"
      v-show="!editorOpen"
      @pointermove.passive="setUiVisible"
      @pointerdown.passive="setUiVisible"
      @touchstart.passive="tapPatch.onTouchStart"
      @touchend="tapPatch.onTouchEnd"
      @touchcancel.passive="tapPatch.onTouchCancel"
    >
      <div class="top-bar-left" v-if="photoswipe">
        <NcButton
          variant="tertiary-no-background"
          :aria-label="t('memories', 'Back')"
          :title="t('memories', 'Back')"
          @click="
            beep();
            close();
          "
        >
          <template #icon>
            <BackIcon :size="24" />
          </template>
        </NcButton>
      </div>

      <div class="top-bar" v-if="photoswipe">
        <NcActions :inline="numInlineTopActions" container=".memories-viewer .pswp">
          <NcActionButton
            v-for="action of topActions"
            :key="action.id"
            :aria-label="action.name"
            close-after-click
            @click="
              beep();
              action.callback();
            "
          >
            {{ action.name }}
            <template #icon>
              <component :is="action.icon" :size="24" v-bind="action.iconArgs ?? {}" />
            </template>
          </NcActionButton>
        </NcActions>
      </div>

      <div class="top-date" v-if="photoswipe">
        <ViewerDateAddress :photo="currentPhoto" :two-lines="true" />
      </div>

      <div class="bottom-bar" v-if="photoswipe">
        <div class="exif title" v-if="currentPhoto?.imageInfo?.exif?.Title">
          {{ currentPhoto.imageInfo.exif.Title }}
        </div>
        <div class="exif description" v-if="currentPhoto?.imageInfo?.exif?.Description">
          {{ currentPhoto.imageInfo.exif.Description }}
        </div>
        <ViewerDateAddress :photo="currentPhoto" :two-lines="false" />
      </div>

      <MobileBottomBar v-if="photoswipe && bottomActions.length" class="viewer-mobile-actions" dark>
        <button
          v-for="action of bottomActions"
          :key="action.id"
          class="mobile-bottom-bar-item"
          :aria-label="action.name"
          :title="action.name"
          @click="
            beep();
            action.callback();
          "
        >
          <component :is="action.icon" :size="24" v-bind="action.iconArgs ?? {}" />
          <span class="label">{{ action.name }}</span>
        </button>
      </MobileBottomBar>
    </div>

    <ViewerSheetGestures
      v-if="windowDims.isMobile && photoswipe"
      :photoswipe="photoswipe"
      @open="setBottomSheet(true)"
    />
    <ViewerBottomSheet v-if="sheetOpen && windowDims.isMobile" :photo="currentPhoto" @close="setBottomSheet(false)" />
  </div>
</template>

<script setup lang="ts">
import { computed, markRaw, nextTick, onMounted, reactive, ref, useTemplateRef, watch } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcButton from '@nextcloud/vue/components/NcButton';
import { showError } from '@services/utils/dialog';
import axios from '@nextcloud/axios';

import { config } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { routeIs } from '@services/router';
import { API } from '@services/API';
import { t } from '@services/l10n';
import { constants } from '@services/constants';
import initstate from '@services/init-state';
import { makeTapPatch } from '@services/compat/mobile-click';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';
import { cacheData, getCachedData } from '@services/cache';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import { RenewingTimeout } from '@services/utils/renewing-timeout';
import * as nativex from '@native';

import ImageEditor from './ImageEditor.vue';
import ViewerDateAddress from './ViewerDateAddress.vue';
import ViewerBottomSheet from './ViewerBottomSheet.vue';
import ViewerSheetGestures from './ViewerSheetGestures.vue';
import MobileBottomBar from '@components/MobileBottomBar.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';
import PhotoSwipe, { type PhotoSwipeOptions } from 'photoswipe';
import 'photoswipe/style.css';
import PsImage from './PsImage';
import PsVideo from './PsVideo';
import PsLivePhoto from './PsLivePhoto';
import PsPhotoSphere from './PsPhotoSphere';

import type { IImageInfo, IPhoto, TimelineState } from '@typings';
import type { PsContent } from './types';
import type { MediaPlayerElement } from 'vidstack/elements';

import LivePhotoIcon from '@components/icons/LivePhoto.vue';
import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import PanoramaSphereIcon from 'vue-material-design-icons/PanoramaSphereOutline.vue';
import PanoramaHorizontalOutlineIcon from 'vue-material-design-icons/PanoramaHorizontalOutline.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import InfoIcon from 'vue-material-design-icons/InformationOutline.vue';
import SidebarIcon from 'vue-material-design-icons/DockRight.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import SlideshowIcon from 'vue-material-design-icons/PlayBox.vue';
import EditFileIcon from 'vue-material-design-icons/FileEdit.vue';
import AlbumRemoveIcon from 'vue-material-design-icons/BookRemove.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import RotateLeftIcon from 'vue-material-design-icons/RotateLeft.vue';

type IViewerAction = {
  /** Identifier (optional) */
  id: string;
  /** Display text */
  name: string;
  /** Icon component */
  icon: any;
  /** Props on icon component */
  iconArgs?: any;
  /** Action to perform */
  callback: () => void;
  /** Condition to check for including */
  if: boolean;
};

const DEFAULT_SLIDESHOW_MS = 5000;
const SIDEBAR_DEBOUNCE_MS = 350;

defineOptions({
  name: 'Viewer',
});
const outer = useTemplateRef<HTMLDivElement>('outer');
const inner = useTemplateRef<HTMLDivElement>('inner');

const loading = ref(0);
const isOpen = ref(false);
let originalTitle: string | null = null;
const editorOpen = ref(false);

const show = ref(false);
const fullyOpened = ref(false);
const sidebarOpen = ref(false);
const outerWidth = ref('100vw');

/** Mobile bottom sheet with photo metadata */
const sheetOpen = ref(false);

/** User interaction detection */
let activityTimer = 0;

/** Base dialog */
const photoswipe = ref<PhotoSwipe | null>(null);
const psVideo = ref<PsVideo | null>(null);
const psImage = ref<PsImage | null>(null);
const psLivePhoto = ref<PsLivePhoto | null>(null);
const psPhotoSphere = ref<PsPhotoSphere | null>(null);

/** Live photo state */
const liveState = reactive({
  playing: false,
  waiting: false,
});

/** List globals */
const list = ref<IPhoto[]>([]);
const globalCount = ref(0);
const globalAnchor = ref(-1);
const currIndex = ref(-1);

/** Timer to move to next photo */
const slideshowTimer = ref(0);
/** Timer to debounce changes to sidebar */
const sidebarUpdateTimer = new RenewingTimeout();
/** Abort scope for sidebar loads; renewed per photo */
const sidebarAbort = useAbort();

/** Photo keys for which an imageInfo request is currently ongoing */
const imageInfoLoading = new Set<string>();

/** Tap-to-click patch handlers for viewer chrome buttons */
const tapPatch = markRaw(
  makeTapPatch({
    selectors: [
      '.top-bar button',
      '.top-bar-left button',
      '.top-date .date-line',
      '.bottom-bar .exif.date',
      '.viewer-mobile-actions button',
      '.v-popper__popper button, .v-popper__popper a',
    ],
  }),
);

onMounted(() => {
  // The viewer is a singleton
  _m.viewer = {
    open: setFragment,
    openDynamic: openDynamic,
    openStatic: openStatic,
    close: close,
    get isOpen() {
      return isOpen.value;
    },
    get currentPhoto() {
      return currentPhoto.value;
    },
  };
});

utils.useBus('memories:sidebar:opened', handleAppSidebarOpen);
utils.useBus('memories:sidebar:closed', handleAppSidebarClose);
utils.useBus('files:file:created', handleFileUpdated);
utils.useBus('files:file:updated', handleFileUpdated);
utils.useBus('memories:fragment:pop:viewer', close);

/** Number of top bar buttons to show inline */
const numInlineTopActions = computed((): number => {
  if (windowDims.isMobile) {
    return Math.min(topActions.value.length, 1);
  }

  let base = 3;
  if (canShare.value) {
    base++;
  }
  if (canEdit.value) {
    base++;
  }

  return Math.min(base, 5);
});

/** Top bar actions, excluding anything visible in the mobile bottom bar */
const topActions = computed((): IViewerAction[] => {
  if (!windowDims.isMobile) {
    return actions.value;
  }

  const bottomIds = new Set(bottomActions.value.map((action) => action.id));
  return actions.value.filter((action) => !bottomIds.has(action.id));
});

/** Bottom bar actions on mobile */
const bottomActions = computed((): IViewerAction[] => {
  if (!windowDims.isMobile) {
    return [];
  }

  // Bottom bar uses a fixed independent order.
  const edit = actions.value.some((a) => a.id === 'edit') ? 'edit' : 'edit-metadata';
  const order = ['share', edit, 'add-to-album', 'delete', 'remove-from-album'];

  // Get all actions available in this order.
  return actions.value
    .filter((action) => order.includes(action.id))
    .sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id))
    .map((action) => {
      // Some names may be too long for the bottom bar.
      if (action.id === 'add-to-album') {
        return { ...action, name: t('memories', 'Add to') };
      } else if (action.id === 'remove-from-album') {
        return { ...action, name: t('memories', 'Remove') };
      } else if (action.id === 'edit-metadata') {
        return { ...action, name: t('memories', 'Edit') };
      }
      return action;
    });
});

/** Get the currently open photo */
const currentPhoto = computed((): IPhoto | null => {
  if (!list.value.length || !photoswipe.value) return null;

  const idx = currIndex.value - globalAnchor.value;
  if (idx < 0 || idx >= list.value.length) return null;

  return list.value[idx];
});

/** Get all actions to show */
const actions = computed((): IViewerAction[] => {
  return [
    {
      id: 'favorite',
      name: t('memories', 'Favorite'),
      icon: isFavorite.value ? markRaw(StarIcon) : markRaw(StarOutlineIcon),
      callback: favoriteCurrent,
      if: !routeIs.Public && !isLocal.value,
    },
    {
      id: 'share',
      name: t('memories', 'Share'),
      icon: markRaw(ShareIcon),
      callback: shareCurrent,
      if: canShare.value,
    },
    {
      id: 'delete',
      name: t('memories', 'Delete'),
      icon: markRaw(DeleteIcon),
      callback: deleteCurrent,
      if: !routeIs.Albums && canDelete.value,
    },
    {
      id: 'remove-from-album',
      name: t('memories', 'Remove from album'),
      icon: markRaw(AlbumRemoveIcon),
      callback: deleteCurrent,
      if: routeIs.Albums,
    },
    {
      id: 'play-live-photo',
      name: t('memories', 'Play Live Photo'),
      icon: markRaw(LivePhotoIcon),
      iconArgs: {
        playing: liveState.playing,
        spin: liveState.waiting,
      },
      callback: playLivePhoto,
      if: isLivePhoto.value,
    },
    {
      id: 'view-panorama',
      name: t('memories', 'View panorama'),
      icon: currentPhoto.value?.pano === 2 ? markRaw(PanoramaSphereIcon) : markRaw(PanoramaHorizontalOutlineIcon),
      callback: toggleSphere,
      if: isPanorama.value && !isVideo.value,
    },
    {
      id: 'info',
      name: t('memories', 'Info'),
      icon: markRaw(InfoIcon),
      callback: toggleInfo,
      if: true,
    },
    {
      id: 'sidebar',
      name: t('memories', 'Sidebar'),
      icon: markRaw(SidebarIcon),
      callback: toggleSidebar,
      if: windowDims.isMobile && !nativex.has(),
    },
    {
      id: 'edit',
      name: t('memories', 'Edit'),
      icon: markRaw(TuneIcon),
      callback: openEditor,
      if: canEdit.value && !isVideo.value,
    },
    {
      id: 'download',
      name: t('memories', 'Download'),
      icon: markRaw(DownloadIcon),
      callback: downloadCurrent,
      if: canDownload.value,
    },
    {
      id: 'download-video',
      name: t('memories', 'Download Video'),
      icon: markRaw(DownloadIcon),
      callback: downloadCurrentLiveVideo,
      if: canDownload.value && !!currentPhoto.value?.liveid,
    },
    ...stackedRaw.value.map((raw) => ({
      id: `download-raw-${raw.fileid}`,
      name: t('memories', 'Download {ext}', { ext: raw.extension }),
      icon: markRaw(DownloadIcon),
      callback: () => downloadByFileId(raw.fileid),
      if: canDownload.value,
    })),
    {
      id: 'view-in-folder',
      name: t('memories', 'View in folder'),
      icon: markRaw(OpenInNewIcon),
      callback: viewInFolder,
      if: !routeIs.Public && !routeIs.Albums && !isLocal.value,
    },
    {
      id: 'slideshow',
      name: t('memories', 'Slideshow'),
      icon: markRaw(SlideshowIcon),
      callback: startSlideshow,
      if: globalCount.value > 1,
    },
    {
      id: 'edit-metadata',
      name: t('memories', 'Edit metadata'),
      icon: markRaw(EditFileIcon),
      callback: () => editMetadata(),
      if: canEdit.value,
    },
    {
      id: 'rotate-flip',
      name: t('memories', 'Rotate / Flip'),
      icon: markRaw(RotateLeftIcon),
      callback: () => editMetadata([5]),
      if: canEdit.value && !isVideo.value,
    },
    {
      id: 'add-to-album',
      name: t('memories', 'Add to album'),
      icon: markRaw(AlbumIcon),
      callback: updateAlbums,
      if:
        config.albums_enabled &&
        !isLocal.value &&
        !routeIs.Public &&
        canShare.value &&
        !!currentPhoto.value?.imageInfo?.filename,
    },
  ].filter((action) => action.if);
});

/** Is the current slide a video */
const isVideo = computed((): boolean => {
  return Boolean((currentPhoto.value?.flag ?? 0) & constants.FLAG_IS_VIDEO);
});

/** Is the current slide a live photo */
const isLivePhoto = computed((): boolean => {
  return Boolean(currentPhoto.value?.liveid);
});

/** Is the current slide a panorama */
const isPanorama = computed((): boolean => {
  return (currentPhoto.value?.pano ?? 0) > 0;
});

/** Is the current slide a local photo */
const isLocal = computed((): boolean => {
  return utils.isLocalPhoto(currentPhoto.value!);
});

/** Is the current photo a favorite */
const isFavorite = computed(() => {
  const p = currentPhoto.value;
  if (!p) return false;
  return Boolean(p.flag & constants.FLAG_IS_FAVORITE);
});

/** Allow closing the viewer */
const allowClose = computed((): boolean => {
  return !editorOpen.value && !dav.isSingleItem() && !slideshowTimer.value;
});

/** Show edit buttons */
const canEdit = computed((): boolean => {
  return currentPhoto.value?.imageInfo?.permissions?.includes('U') ?? false;
});

/** Show delete button */
const canDelete = computed((): boolean => {
  return currentPhoto.value?.imageInfo?.permissions?.includes('D') ?? false;
});

/** Show share button and add to album button */
const canShare = computed((): boolean => {
  return !!currentPhoto.value;
});

/** Show download button */
const canDownload = computed((): boolean => {
  return !currentPhoto.value?.imageInfo?.permissions?.includes('L') && !initstate.noDownload && !isLocal.value;
});

/** Stacked RAW photos */
const stackedRaw = computed((): { extension: string; fileid: number }[] => {
  const photo = currentPhoto.value;
  if (!photo || !photo.stackraw?.length) return [];

  return photo.stackraw.map((raw) => ({
    extension: (raw.basename?.split('.').pop() ?? '?').toUpperCase(),
    fileid: raw.fileid,
  }));
});

watch(allowClose, (val) => {
  if (!photoswipe.value) return;
  photoswipe.value.options.pinchToClose = val;
  photoswipe.value.options.closeOnVerticalDrag = val;
});

watch(windowDims, () => {
  if (sheetOpen.value) sheetOpen.value = windowDims.isMobile;
  if (show.value) photoswipe.value?.updateSize();
});

function updateLoading(delta: number) {
  loading.value += delta;
}

/** Update the document title */
function updateTitle(photo: IPhoto | undefined) {
  originalTitle ||= document.title;
  if (photo) {
    document.title = `${photo.basename} - ${originalTitle}`;
  } else {
    document.title = originalTitle;
    originalTitle = null;
  }
}

/** Event on file changed */
function handleFileUpdated({ fileid }: { fileid: number }) {
  const photo = currentPhoto.value;
  const isvideo = (photo?.flag ?? 0) & constants.FLAG_IS_VIDEO;
  if (photo?.fileid === fileid && !isvideo) {
    photoswipe.value?.refreshSlideContent(currIndex.value);
  }
}

/** User interacted with the page with mouse */
function setUiVisible(event: PointerEvent | false) {
  clearTimeout(activityTimer);
  if (event) {
    // If directly triggered, always update ui visibility
    // If triggered through a pointer event, only update if this is not
    // a touch event (i.e. a mouse move).
    // On touch devices, tapAction directly handles the ui visibility
    // through Photoswipe.
    const isPointer = event instanceof PointerEvent;
    const isMouse = isPointer && event.pointerType !== 'touch';
    if (isOpen.value && (!isPointer || isMouse)) {
      photoswipe.value?.template?.classList.add('pswp--ui-visible');

      if (isMouse) {
        activityTimer = window.setTimeout(() => {
          if (isOpen.value) {
            photoswipe.value?.template?.classList.remove('pswp--ui-visible');
          }
        }, 2000);
      }
    }
  } else {
    photoswipe.value?.template?.classList.remove('pswp--ui-visible');
  }
}

/** Create the base photoswipe object */
async function createBase(args: PhotoSwipeOptions) {
  show.value = true;
  sheetOpen.value = false;
  await nextTick();

  const pswp = new PhotoSwipe({
    counter: false,
    close: false,
    zoom: false,
    loop: false,
    wheelToZoom: true,
    bgOpacity: 1,
    appendToEl: inner.value!,
    preload: [2, 2],
    bgClickAction: 'toggle-controls',

    clickToCloseNonZoomable: false,
    pinchToClose: allowClose.value,
    closeOnVerticalDrag: allowClose.value,

    easing: 'cubic-bezier(.49,.85,.55,1)',
    showHideAnimationType: 'zoom',
    showAnimationDuration: 250,
    hideAnimationDuration: 250,

    closeTitle: t('memories', 'Close'),
    arrowPrevTitle: t('memories', 'Previous'),
    arrowNextTitle: t('memories', 'Next'),
    getViewportSizeFn: () => {
      // Ignore the sidebar if mobile or fullscreen
      const isFullscreen = Boolean(document.fullscreenElement);
      const use = sidebarOpen.value && !windowDims.isMobile && !isFullscreen;

      // Calculate the sidebar width to use and outer width
      const sidebarWidth = use ? _m.sidebar.getWidth() : 0;
      outerWidth.value = `calc(100vw - ${sidebarWidth}px)`;

      return {
        x: windowDims.width - sidebarWidth,
        y: windowDims.height,
      };
    },
    ...args,
  });

  // PhotoSwipe relies on object identity internally, and Vue's
  // reactivity proxying breaks it. All photoswipe-related objects
  // MUST use markRaw() if stored in data.
  photoswipe.value = markRaw(pswp);

  // Debugging only
  _m.viewer.photoswipe = photoswipe.value;

  // Check if someone else is trapping focus
  const hasNestedTrap = (e: Event): Element | null => {
    const selectors = ['#app-sidebar-vue', '#app-sidebar-native', '.v-popper__popper', '.modal-mask', '.oc-dialog'];
    if (e.target instanceof Element) {
      return e.target.closest(selectors.join(','));
    }
    return null;
  };

  // Monkey patch for focus trapping in sidebar
  const psKeyboard = photoswipe.value.keyboard as any;
  const _onFocusIn = psKeyboard['_onFocusIn'];
  console.assert(_onFocusIn, 'Missing _onFocusIn for monkey patch');
  psKeyboard['_onFocusIn'] = (e: FocusEvent) => {
    if (hasNestedTrap(e)) return;
    _onFocusIn.call(photoswipe.value!.keyboard, e);
  };

  // Refresh sidebar on change
  photoswipe.value.on('change', () => {
    if (sidebarOpen.value) {
      openSidebar();
    }
  });

  // Handle keydown
  photoswipe.value.on('keydown', (e) => {
    if (e.defaultPrevented) return;

    // Check if someone else is trapping focus.
    // For the sidebar, however, we want to continue executing our actions.
    // https://github.com/pulsejet/memories/issues/1414
    const nested = hasNestedTrap(e.originalEvent);
    if (nested && nested.id !== 'app-sidebar-vue' && nested.id !== 'app-sidebar-native') {
      e.preventDefault();
      return;
    }

    keydown(e.originalEvent);
  });

  // Make sure buttons are styled properly
  photoswipe.value.addFilter('uiElement', (element, data) => {
    // add button-vue class if button
    if (element.classList.contains('pswp__button')) {
      element.classList.add('button-vue');
    }
    return element;
  });

  // Total number of photos in this view
  photoswipe.value.addFilter('numItems', () => globalCount.value);

  // Put viewer over everything else
  const navElem = document.getElementById('app-navigation-vue');
  photoswipe.value.on('beforeOpen', () => {
    navElem?.style.setProperty('z-index', '0');
  });
  photoswipe.value.on('openingAnimationStart', () => {
    isOpen.value = true;
    fullyOpened.value = false;
    if (sidebarOpen.value) {
      openSidebar();
    }
    nativex.setTheme('#000000', true); // viewer is always dark
  });
  photoswipe.value.on('openingAnimationEnd', () => {
    fullyOpened.value = true;
  });
  photoswipe.value.on('close', () => {
    isOpen.value = false;
    fullyOpened.value = false;
    sheetOpen.value = false;
    setUiVisible(false);
    sidebarAbort.abort();
    hideSidebar();
    setFragment(null);
    updateTitle(undefined);
    nativex.setTheme(); // reset
  });
  photoswipe.value.on('destroy', () => {
    navElem?.style.setProperty('z-index', '');

    // reset everything
    show.value = false;
    isOpen.value = false;
    fullyOpened.value = false;
    editorOpen.value = false;
    sheetOpen.value = false;
    photoswipe.value = null;
    list.value = [];
    globalCount.value = 0;
    globalAnchor.value = -1;
    clearTimeout(slideshowTimer.value);
    slideshowTimer.value = 0;
  });

  // Update vue route for deep linking
  photoswipe.value.on('slideActivate', (e) => {
    currIndex.value = photoswipe.value!.currIndex;
    const photo = e.slide?.data?.photo;
    setFragment(photo);
    updateTitle(photo);

    // Remove active class from others and add to this one
    photoswipe.value!.element?.querySelectorAll('.pswp__item').forEach((el) => el.classList.remove('active'));
    e.slide.holderElement?.classList.add('active');
  });

  // Video support
  const psVideoInstance = new PsVideo(<any>photoswipe.value);
  psVideo.value = markRaw(psVideoInstance);

  // Image support
  psImage.value = markRaw(new PsImage(<any>photoswipe.value));

  // Live Photo support
  psLivePhoto.value = markRaw(new PsLivePhoto(<any>photoswipe.value, <any>psImage.value, liveState));

  // Panorama sphere support
  psPhotoSphere.value = markRaw(new PsPhotoSphere(<any>photoswipe.value));

  // Patch the close button to stop the slideshow
  const _close = photoswipe.value.close.bind(photoswipe.value);
  photoswipe.value.close = () => {
    if (slideshowTimer.value) {
      stopSlideshow();
    } else {
      _close();
    }
  };

  // Patch the next/prev buttons to reset slideshow timer
  const _next = photoswipe.value.next.bind(photoswipe.value);
  const _prev = photoswipe.value.prev.bind(photoswipe.value);
  photoswipe.value.next = () => {
    resetSlideshowTimer();
    _next();
  };
  photoswipe.value.prev = () => {
    resetSlideshowTimer();
    _prev();
  };

  return photoswipe.value;
}

/** Set the route hash to the given photo */
function setFragment(photo: IPhoto | null) {
  // Add or update fragment
  if (photo) {
    return utils.fragment.push(utils.fragment.types.viewer, String(photo.dayid), photo.key!);
  }

  // Remove fragment if closed
  if (!isOpen.value) {
    return utils.fragment.pop(utils.fragment.types.viewer);
  }
}

/** Open using start photo and rows list */
async function openDynamic(anchorPhoto: IPhoto, timeline: TimelineState) {
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
    const anchorDayId = list.value[0].dayid;
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
  list.value = [...detail];

  // Compute globals
  let globals = computeGlobals();
  globalAnchor.value = globals.anchor;
  globalCount.value = globals.count;

  // Create basic viewer
  const startIndex = detail.indexOf(anchorPhoto);
  const pswp = await createBase({
    index: globalAnchor.value + startIndex,
  });

  // Debounce the global recompute to once per cycle
  const refreshGlobals = new RenewingTimeout();

  // Lazy-generate item data. This is called for each item in the list
  pswp!.addFilter('itemData', (itemData, index) => {
    if (!list.value) return {};
    const { dayIds } = globals;

    // Once every cycle, refresh the globals
    refreshGlobals.set(() => {
      if (!photoswipe.value) return;
      globals = computeGlobals();
      let goTo: null | number = null; // final index of photoswipe

      // If the anchor shifts to the left, we need to shift the index
      // by the same amount. This happens synchronously, so update first.
      // Also check if the current position is invalid here
      if (globals.anchor != globalAnchor.value) {
        goTo = photoswipe.value.currIndex - (globalAnchor.value - globals.anchor);
      } else if (photoswipe.value.currIndex >= globals.count || photoswipe.value.currIndex < 0) {
        goTo = photoswipe.value.currIndex; // equivalent to above
      }

      // Update the global anchor and count
      globalCount.value = globals.count;
      globalAnchor.value = globals.anchor;

      // Go to the new index if needed
      if (goTo === null) {
        // no change
      } else {
        // Change the index to the new one with clamp
        goTo = utils.clamp(goTo, 0, globals.count - 1);
        photoswipe.value.goTo(goTo);

        // Make sure the slide is current, since this call is deferred
        // https://github.com/pulsejet/memories/issues/1194
        photoswipe.value.refreshSlideContent(goTo);
      }
    }, 0);

    // Get photo object from list
    let idx = index - globalAnchor.value;
    if (idx < 0) {
      // Load previous day
      const firstDayId = list.value[0].dayid;
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
      list.value.unshift(...prevDay.detail);
      globalAnchor.value -= prevDay.count;
    } else if (idx >= list.value.length) {
      // Load next day
      const lastDayId = list.value.at(-1)!.dayid;
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
      list.value.push(...nextDay.detail);
    }

    idx = index - globalAnchor.value;
    const photo = list.value[idx];

    // Something went really wrong
    console.assert(!!photo, 'Missing photo for index', index, 'and global anchor', globalAnchor.value);
    if (!photo) return {};

    // Get index of current day in dayIds list
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

    const data = getItemData(photo);
    data.msrc = thumbElem(photo)?.getAttribute('src') ?? utils.getPreviewUrl({ photo, msize: 256 });
    return data;
  });

  // Get the thumbnail image
  pswp!.addFilter('thumbEl', (thumbEl, data, index) => {
    const photo = list.value[index - globalAnchor.value];
    if (!photo || !photo.w || !photo.h) return thumbEl as HTMLElement;
    return thumbElem(photo) ?? (thumbEl as HTMLElement); // bug in PhotoSwipe types
  });

  pswp!.on('slideActivate', (e) => {
    // Scroll to keep the thumbnail in view
    const thumb = thumbElem(e.slide.data?.photo);
    if (thumb && fullyOpened.value) {
      const rect = thumb.getBoundingClientRect();
      if (rect.bottom < 50 || rect.top > windowDims.height - 50) {
        thumb.scrollIntoView({ block: 'center' });
      }
    }
  });

  pswp!.init();
}

/** Close the viewer */
function close() {
  if (!isOpen.value) return;
  photoswipe.value?.close();
}

/** Play native tap sound on button press */
function beep() {
  nativex.playTouchSound();
}

/** Open with a static list of photos */
async function openStatic(photo: IPhoto, listArg: IPhoto[], thumbSize?: 256 | 512) {
  list.value = listArg;
  const pswp = await createBase({
    index: listArg.findIndex((p) => p.fileid === photo.fileid),
  });

  globalCount.value = listArg.length;
  globalAnchor.value = 0;

  pswp!.addFilter('itemData', (itemData, index) => ({
    ...getItemData(list.value[index]),
    msrc: thumbSize ? utils.getPreviewUrl({ photo: list.value[index], msize: thumbSize }) : undefined,
  }));

  isOpen.value = true;
  pswp!.init();
}

/** Get base data object */
function getItemData(photo: IPhoto): PsContent['data'] {
  let previewUrl = utils.getPreviewUrl({ photo, size: 'screen' });
  const isvideo = photo.flag & constants.FLAG_IS_VIDEO;

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
  loadMetadata(photo);

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
  const highSrcCond = config.high_res_cond || config.high_res_cond_default || 'zoom';

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
}

/** Get element for thumbnail if it exists */
function thumbElem(photo: IPhoto): HTMLImageElement | undefined {
  if (!photo) return;
  const elems = Array.from(document.querySelectorAll(`.memories-thumb-${photo.key}`));

  if (elems.length === 0) return;
  if (elems.length === 1) return elems[0] as HTMLImageElement;

  // Find if any element has the important class
  const important = elems.filter((e) => e.classList.contains('memories-thumb-important'));
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
}

/**
 * Load the metadata (image info) for a photo asynchronously
 */
async function loadMetadata(photo: IPhoto) {
  // Check if already loaded
  if (photo.imageInfo) return;

  // Check if already loading
  const key = photo.key ?? photo.fileid.toString();
  if (imageInfoLoading.has(key)) return;

  // Mark as loading
  imageInfoLoading.add(key);

  // Get a consistent URL so we can cache.
  const url = utils.getImageInfoUrl(photo, config);

  // Apply image data onto the photo.
  const applyImageInfo = (data: IImageInfo) => {
    photo.imageInfo = data;
    photo.w = data.w;
    photo.h = data.h;
    photo.basename = data.basename;
    photo.mimetype = data.mimetype;
  };

  // Get cached data first.
  let wasCached = false;
  try {
    const cached = await getCachedData<IImageInfo>(url);
    if (cached) {
      applyImageInfo(cached);
      wasCached = true;
    }
  } catch {
    // cache miss
  }

  // Attempt to refresh the cached data.
  try {
    const res = await axios.get<IImageInfo>(url);
    applyImageInfo(res.data);
    cacheData(url, res.data);
  } catch (e) {
    if (wasCached) return;
    throw e;
  } finally {
    // Allow another chance in case this failed
    imageInfoLoading.delete(key);
  }
}

async function openEditor() {
  // Only for JPEG for now
  if (!canEdit.value) return;

  // Prevent editing Live Photos
  if (isLivePhoto.value) {
    showError(t('memories', 'Editing is currently disabled for Live Photos'));
    return;
  }

  // Open editor
  editorOpen.value = true;
}

/** Share the current photo externally */
function shareCurrent() {
  _m.modals.sharePhotos([currentPhoto.value!]);
}

/** Key press events */
function keydown(e: KeyboardEvent) {
  if (e.defaultPrevented) return;

  if (e.key === 'Delete') {
    deleteCurrent();
  }

  if (e.key === 'Tab') {
    photoswipe.value?.element?.classList.add('pswp--ui-visible');
  }

  if (e.key === 'F' && e.shiftKey) {
    outer.value?.requestFullscreen();
  }

  if (e.key === 'A' && e.shiftKey) {
    updateAlbums();
  }

  if (e.key === 'M' && e.shiftKey) {
    editMetadata();
  }
}

/** Delete this photo and refresh */
async function deleteCurrent() {
  let idx = photoswipe.value!.currIndex - globalAnchor.value;
  const photo = list.value[idx];
  if (!photo) return;

  // Delete with WebDAV
  try {
    updateLoading(1);
    for await (const p of dav.deletePhotos([photo])) {
      if (!p[0]) return;
    }
  } catch {
    return;
  } finally {
    updateLoading(-1);
  }

  // Remove from main view
  utils.bus.emit('memories:timeline:deleted', [photo]);

  // If this is the only photo, close viewer
  if (list.value.length === 1) {
    return close();
  }

  // If this is the last photo, move to the previous photo first
  // https://github.com/pulsejet/memories/issues/269
  if (idx === list.value.length - 1) {
    photoswipe.value!.prev();

    // Some photos might lazy load, so recompute idx for the next element
    idx = photoswipe.value!.currIndex + 1 - globalAnchor.value;
  }

  list.value.splice(idx, 1);
  globalCount.value--;
  for (let i = idx - 3; i <= idx + 3; i++) {
    photoswipe.value!.refreshSlideContent(i + globalAnchor.value);
  }
}

/** Play the current live photo */
function playLivePhoto() {
  psLivePhoto.value?.play(photoswipe.value!.currSlide!.content as PsContent);
}

/** Toggle the panorama sphere viewer */
function toggleSphere() {
  void psPhotoSphere.value?.toggle();
}

/** Favorite the current photo */
async function favoriteCurrent() {
  const photo = currentPhoto.value!;
  const val = !isFavorite.value;
  try {
    updateLoading(1);
    for await (const p of dav.favoritePhotos([photo], val)) {
      // Do nothing
    }
  } finally {
    updateLoading(-1);
  }
}

/** Download a file by file ID */
async function downloadByFileId(fileId: number) {
  dav.downloadFiles([fileId]);
}

/** Download the current photo */
async function downloadCurrent() {
  const photo = currentPhoto.value;
  if (!photo) return;
  downloadByFileId(photo.fileid);
}

/** Download live part of current video */
async function downloadCurrentLiveVideo() {
  const photo = currentPhoto.value;
  if (!photo) return;
  dav.downloadFromUrl(utils.getLivePhotoVideoUrl(photo, false));
}

/**
 * Open the sidebar.
 *
 * Calls to this function are debounced to prevent too many updates
 * to the sidebar while the user is scrolling through photos.
 */
async function openSidebar() {
  const photo = currentPhoto.value;
  if (!photo) return;
  const signal = sidebarAbort.renew();

  // Invalidate currently open metadata
  _m.sidebar.invalidateUnless(photo.fileid);

  // Update the sidebar, first call immediate
  sidebarUpdateTimer.set(
    async () => {
      if (signal.aborted || !isOpen.value) return;

      if (!_m.sidebar.isOpen()) {
        _m.sidebar.setTab('memories-metadata');
      }

      if (routeIs.Public || isLocal.value) {
        _m.sidebar.open(photo);
      } else {
        try {
          const fileInfo = (await dav.getFiles([photo], { signal }))[0];
          signal.throwIfAborted();
          if (!fileInfo) return;

          // get attributes
          const filename = fileInfo?.filename;
          const useNative = fileInfo?.originalFilename?.startsWith('/files/');

          // open sidebar
          _m.sidebar.open(photo, filename, useNative);
        } catch (e) {
          if (isAbortError(e)) return;
          throw e;
        }
      }
    },
    SIDEBAR_DEBOUNCE_MS,
    true,
  );
}

function handleAppSidebarOpen() {
  if (show.value && photoswipe.value) {
    sidebarOpen.value = true;
    photoswipe.value.updateSize();
  }
}

function handleAppSidebarClose() {
  if (show.value && photoswipe.value && fullyOpened.value) {
    sidebarOpen.value = false;
    photoswipe.value.updateSize();
  }
}

/** Hide the sidebar, without marking it as closed */
function hideSidebar() {
  _m.sidebar.close();
}

/** Close the sidebar */
function closeSidebar() {
  hideSidebar();
  sidebarOpen.value = false;
  photoswipe.value?.updateSize();
}

/** Toggle the sidebar visibility */
function toggleSidebar() {
  if (sidebarOpen.value) {
    closeSidebar();
  } else {
    setBottomSheet(false);
    openSidebar();
  }
}

/** Toggle photo info: bottom sheet on mobile, sidebar otherwise */
function toggleInfo() {
  if (windowDims.isMobile) {
    setBottomSheet();
  } else {
    toggleSidebar();
  }
}

/** Open, close, or toggle the mobile bottom sheet */
function setBottomSheet(want?: boolean) {
  want ??= !sheetOpen.value;
  if (want === sheetOpen.value) return;
  if (want && (!currentPhoto.value || editorOpen.value)) return;
  if (want && sidebarOpen.value) closeSidebar();
  sheetOpen.value = want;
}

/**
 * Open the files app with the current file.
 */
async function viewInFolder() {
  dav.viewInFolder(currentPhoto.value!);
}

/**
 * Start a slideshow
 */
async function startSlideshow() {
  // Full screen the outer element
  if (!outer.value?.requestFullscreen()) return;

  // Hide controls
  setTimeout(() => setUiVisible(false), 1);

  // Start slideshow
  slideshowTimer.value = window.setTimeout(slideshowTimerFired, getSlideshowMs());
}

/**
 * Event of slideshow timer fire
 */
function slideshowTimerFired() {
  // Cancel if timer doesn't exist anymore
  // This can happen e.g. due to videos
  if (!slideshowTimer.value) return;

  // If this is a video, wait for it to finish
  if (isVideo.value) {
    // Get active player element
    const player = photoswipe.value?.element?.querySelector<MediaPlayerElement>('.pswp__item.active media-player');

    // If no player is found by now, something likely went wrong. Just skip ahead.
    // Otherwise check if video is not ended yet
    if ((player?.currentTime ?? Infinity) < (player?.duration ?? 0) - 0.1) {
      // Wait for video to finish
      player?.addEventListener('ended', slideshowTimerFired, { once: true });
      return;
    }
  }

  photoswipe.value?.next();
  // no need to set the timer again, since next
  // calls resetSlideshowTimer anyway
}

/**
 * Restart the slideshow timer
 */
function resetSlideshowTimer() {
  if (slideshowTimer.value) {
    window.clearTimeout(slideshowTimer.value);
    slideshowTimer.value = window.setTimeout(slideshowTimerFired, getSlideshowMs());
  }
}

/**
 * Get the slideshow interval in milliseconds from user config
 */
function getSlideshowMs() {
  const secs = Number(config.slideshow_duration);
  if (!Number.isFinite(secs)) return DEFAULT_SLIDESHOW_MS;
  return utils.clamp(Math.round(secs), 1, 60) * 1000;
}

/**
 * Stop the slideshow
 */
function stopSlideshow() {
  window.clearTimeout(slideshowTimer.value);
  slideshowTimer.value = 0;

  // exit full screen
  if (document.fullscreenElement) {
    document.exitFullscreen();
  }
}

/**
 * Detect change in fullscreen
 */
function fullscreenChange() {
  if (!document.fullscreenElement) {
    stopSlideshow();
  }
  photoswipe.value?.updateSize();
  photoswipe.value?.template?.focus();
}

/**
 * Edit metadata for current photo
 */
function editMetadata(sections?: number[]) {
  _m.modals.editMetadata([currentPhoto.value!], sections);
}

/**
 * Update album selection for current photo
 */
function updateAlbums() {
  _m.modals.updateAlbums([currentPhoto.value!]);
}
</script>

<style lang="scss" scoped>
.outer {
  z-index: 2020;
  width: v-bind(outerWidth) !important;
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

.top-bar,
.top-bar-left {
  z-index: 100001;
  position: absolute;
  top: 8px;
  --default-clickable-area: 44px;

  transition: opacity 0.2s ease-in-out;
  opacity: 0;
  pointer-events: none;
  .memories-viewer:has(.pswp--ui-visible):not(.is-slideshow) & {
    opacity: 1;
    pointer-events: auto;
  }

  :deep(.button-vue) {
    color: white;
    background-color: transparent !important;
  }
}

.top-bar-left {
  left: 8px;
}

.top-bar {
  right: 8px;
}

/** Top date is only displayed on mobile. */
.top-date {
  display: none;
  @media (max-width: 768px) {
    display: block;
  }

  z-index: 100001;
  position: absolute;
  top: 15px;
  left: 50%;
  transform: translateX(-50%);
  text-align: center;
  pointer-events: none;

  transition: opacity 0.2s ease-in-out;
  opacity: 0;
  .memories-viewer:has(.pswp--ui-visible):not(.is-slideshow) & {
    opacity: 1;
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
  .memories-viewer:has(.pswp--ui-visible):not(.is-slideshow).fully-opened:not(.is-video) &:has(> .exif),
  .memories-viewer.force-metadata.fully-opened:not(.is-video) &:has(> .exif) {
    opacity: 1;
  }

  .exif {
    @media (max-width: 768px) {
      display: none;
    }
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

  .memories-viewer.is-video & {
    // Videos paint their own gradient inside the slide.
    display: none;
  }
}

.viewer-mobile-actions {
  display: none;
  @media (max-width: 768px) {
    display: flex;
  }

  background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.55));
  width: inherit;
  padding: 8px 8px max(10px, env(safe-area-inset-bottom));
  z-index: 100002;
  position: fixed;
  bottom: 0;
  left: 0;

  transition: opacity 0.2s ease-in-out;
  opacity: 0;
  pointer-events: none;
  .memories-viewer:has(.pswp--ui-visible):not(.is-slideshow) & {
    opacity: 1;
    pointer-events: auto;
  }
}

.fully-opened.is-slideshow :deep(.pswp__container) {
  // Animate transitions
  // Disabled normally because this makes you sick if moving fast
  transition: transform 0.75s ease !important;
}

.inner {
  width: inherit;
  :deep(.pswp) {
    width: inherit;
  }

  :deep(.pswp__top-bar) {
    background: linear-gradient(0deg, transparent, rgba(0, 0, 0, 0.3));
  }

  :deep(.video-container.error) {
    color: red;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

:deep(.pswp) {
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

  // Swipe is disabled in the sphere viewer, so keep arrows
  // reachable on touch screens while it is open.
  &:has(.memories-photosphere) .pswp__button--arrow {
    visibility: visible;
  }
}
</style>

<style lang="scss">
// Video styles
@use './PsVideo.scss';
@use './PsPhotoSphere.scss';

// Prevent the popper from overlapping with the sidebar
.pswp > div > .v-popper__wrapper {
  overflow: visible !important;
  > .v-popper__inner {
    transform: translateX(-15px);
    body:has(aside.app-sidebar) & {
      transform: translateX(-65px);
    }
  }
}
</style>
