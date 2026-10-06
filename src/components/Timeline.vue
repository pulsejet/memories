<template>
  <SwipeRefresh
    class="memories-timeline container no-user-select"
    ref="container"
    match=".recycler"
    :refresh="softRefreshSync"
    :allowSwipe="allowSwipe"
    :state="state"
  >
    <!-- Loading indicator -->
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <!-- Static top matter -->
    <TopMatter ref="topmatter" />

    <!-- No content found and nothing is loading -->
    <EmptyContent v-if="showEmpty" />

    <!-- Top overlay showing date -->
    <TimelineTopOverlay ref="topOverlay" :heads="heads" :container="container?.$el" :recycler="recycler?.$el" />

    <!-- Main recycler view for rows -->
    <RecycleScroller
      ref="recycler"
      class="recycler hide-scrollbar"
      tabindex="1"
      :class="{ empty }"
      :items="list"
      :emit-update="true"
      :buffer="800"
      :skipHover="true"
      key-field="id"
      size-field="size"
      type-field="type"
      :disableTransform="true"
      :updateInterval="100"
      @update="scrollChangeRecycler"
    >
      <template #before>
        <!-- Dynamic top matter, e.g. album or view name -->
        <div class="recycler-before" ref="recyclerBefore">
          <!-- Gap for mobile header -->
          <div class="mobile-header-top-gap"></div>

          <!-- Route-specific top matter -->
          <DynamicTopMatter ref="dtm" @load="scrollerManager?.adjust()" />
        </div>
      </template>

      <template v-slot="{ item, index }">
        <RowHead v-if="item.type === 0" :item="item" @click="selectionManager?.selectHead(item)" />

        <template v-else>
          <Photo
            class="photo top-left"
            v-for="photo of item.photos ?? []"
            :key="photo.key"
            :style="{
              height: `${photo.dispH}px`,
              width: `${photo.dispW}px`,
              transform: `translate(${photo.dispX}px, ${photo.dispY}px)`,
            }"
            :data="photo"
            :day="item.day"
            @select="selectionManager?.clickSelectionIcon(photo, $event, index)"
            @pointerdown="selectionManager?.clickPhoto(photo, $event, index)"
            @touchstart="selectionManager?.touchstartPhoto(photo, $event, index)"
            @touchend="selectionManager?.touchendPhoto(photo, $event, index)"
            @touchmove="selectionManager?.touchmovePhoto(photo, $event, index)"
          />
        </template>
      </template>
    </RecycleScroller>

    <!-- Managers -->
    <ScrollerManager
      ref="scrollerManager"
      v-show="!showEmpty"
      :rows="list"
      :fullHeight="scrollerHeight"
      :recycler="recycler"
      :recyclerBefore="recyclerBefore"
      @interactend="loadScrollView"
      @scroll="
        currentScroll = $event.current;
        topOverlay?.refresh();
      "
    />

    <SelectionManager
      ref="selectionManager"
      :heads="heads"
      :rows="list"
      :isreverse="isMonthView"
      :recycler="recycler?.$el"
      :scrollerManager="scrollerManager"
      @updateLoading="updateLoading"
    />
  </SwipeRefresh>
</template>

<script setup lang="ts">
import {
  computed,
  getCurrentInstance,
  nextTick,
  onBeforeUnmount,
  onMounted,
  onUnmounted,
  ref,
  useTemplateRef,
  watch,
} from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { RouteLocationNormalized } from 'vue-router';
import { RecycleScroller } from 'vue-virtual-scroller';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { getLayout } from '@services/layout';

import { config } from '@services/user-config';
import { windowDims } from '@services/common';
import {
  useRouteIsAlbumShare,
  useRouteIsAlbums,
  useRouteIsArchive,
  useRouteIsBase,
  useRouteIsFavorites,
  useRouteIsFolderShare,
  useRouteIsFolders,
  useRouteIsMap,
  useRouteIsPanoramas,
  useRouteIsPeople,
  useRouteIsPlaces,
  useRouteIsRecognizeUnassigned,
  useRouteIsSearch,
  useRouteIsTags,
  useRouteIsThisDay,
  useRouteIsVideos,
} from '@services/route-checker';
import RowHead from '@components/frame/RowHead.vue';
import Photo from '@components/frame/Photo.vue';
import ScrollerManager from '@components/ScrollerManager.vue';
import SelectionManager from '@components/SelectionManager.vue';
import SwipeRefresh from './SwipeRefresh.vue';

import EmptyContent from '@components/top-matter/EmptyContent.vue';
import TopMatter from '@components/top-matter/TopMatter.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import TimelineTopOverlay from '@components/top-matter/TimelineTopOverlay.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import { constants as c } from '@services/utils';
import * as nativex from '@native';
import { t } from '@services/l10n';

import { API, DaysFilterType } from '@services/API';
import * as lens from '@services/lens';

import type { IDay, IHeadRow, IPhoto, IPhotoRow, IRow } from '@typings';

const SCROLL_LOAD_DELAY = 100; // Delay in loading data when scrolling
const DESKTOP_ROW_HEIGHT = 200; // Height of row on desktop
const MOBILE_ROW_HEIGHT = 120; // Approx row height on mobile
const ROW_NUM_LPAD = 16; // Number of rows to load before and after viewport

defineOptions({
  name: 'Timeline',
});

const route = useRoute();
const router = useRouter();
const routeIsAlbumShare = useRouteIsAlbumShare();
const routeIsAlbums = useRouteIsAlbums();
const routeIsArchive = useRouteIsArchive();
const routeIsBase = useRouteIsBase();
const routeIsFavorites = useRouteIsFavorites();
const routeIsFolderShare = useRouteIsFolderShare();
const routeIsFolders = useRouteIsFolders();
const routeIsMap = useRouteIsMap();
const routeIsPanoramas = useRouteIsPanoramas();
const routeIsPeople = useRouteIsPeople();
const routeIsPlaces = useRouteIsPlaces();
const routeIsRecognizeUnassigned = useRouteIsRecognizeUnassigned();
const routeIsSearch = useRouteIsSearch();
const routeIsTags = useRouteIsTags();
const routeIsThisDay = useRouteIsThisDay();
const routeIsVideos = useRouteIsVideos();
const instance = getCurrentInstance();
const container = useTemplateRef<InstanceType<typeof SwipeRefresh>>('container');
const topmatter = useTemplateRef<InstanceType<typeof TopMatter>>('topmatter');
const dtm = useTemplateRef<InstanceType<typeof DynamicTopMatter>>('dtm');
const topOverlay = useTemplateRef<InstanceType<typeof TimelineTopOverlay>>('topOverlay');
const recycler = useTemplateRef<VueRecyclerType>('recycler');
const recyclerBefore = useTemplateRef<HTMLDivElement>('recyclerBefore');
const selectionManager = useTemplateRef<InstanceType<typeof SelectionManager>>('selectionManager');
const scrollerManager = useTemplateRef<InstanceType<typeof ScrollerManager>>('scrollerManager');

const emit = defineEmits<{
  daysLoaded: [stats: { count: number }];
}>();

/** Loading days response */
const loading = ref(0);
/** Main list of rows */
const list = ref<IRow[]>([]);
/** Dynamic top matter has standalone content */
const dtmContent = ref(false);
/** Computed number of columns */
let numCols = 0;
/** Ordered header rows for dayId key  */
const heads = ref(new Map<number, IHeadRow>());
/** Current list (days response) was loaded from cache */
let daysIsCache = false;

/** Size of outer container [w, h] */
let containerSize: [number, number] = [0, 0];
/** Computed row height */
let rowHeight = 100;
/** Computed row width */
let rowWidth = 100;

/** Current start index */
const currentStart = ref(0);
/** Current end index */
const currentEnd = ref(0);
/** Current physical scroll position */
const currentScroll = ref(0);
/** Resize observer on the outer container */
let resizeObserver: ResizeObserver | null = null;
/** Height of the scroller */
const scrollerHeight = ref(100);

/** Set of dayIds for which images loaded */
const loadedDays = new Set<number>();
/** Set of dayIds for which image size is calculated */
const sizedDays = new Set<number>();
/** Days to load in the next call */
let fetchDayQueue: number[] = [];
/** Timer to load day call */
let fetchDayTimer: number | null = null;

/** Resizing timer */
const resizeTimer = new utils.RenewingTimeout();
/** Timer to debounce scroll loads */
const scrollChangeTimer = new utils.RenewingTimeout();
/** Timer to debounce soft refreshes */
const softRefreshTimer = new utils.RenewingTimeout();

/** State for request cancellations */
const state = ref(Math.random());

watch(router.currentRoute, async (to, from) => {
  await routeChange(to, from);
});

onMounted(() => {
  // Trigger initial state load
  void routeChange(route);

  // Start resize observer on container
  if (container.value?.$el) {
    resizeObserver = new ResizeObserver(() => handleResizeWithDelay());
    resizeObserver.observe(container.value.$el);
  }

  // Template refs ($refs) are not reactive in Vue 3, so prop bindings
  // like :recycler="recycler" evaluated during the initial render
  // stay undefined. Re-render once now that all refs are populated.
  instance?.proxy?.$forceUpdate();
});

onUnmounted(() => {
  resizeObserver?.disconnect();
});

watch(config, softRefresh);
utils.bus.on('files:file:created', softRefresh);
utils.bus.on('memories:timeline:fetch-day', fetchDay);
utils.bus.on('memories:timeline:deleted', deleteFromViewWithAnimation);
utils.bus.on('memories:timeline:soft-refresh', softRefresh);
utils.bus.on('memories:timeline:hard-refresh', refresh);

onBeforeUnmount(() => {
  utils.bus.off('files:file:created', softRefresh);
  utils.bus.off('memories:timeline:fetch-day', fetchDay);
  utils.bus.off('memories:timeline:deleted', deleteFromViewWithAnimation);
  utils.bus.off('memories:timeline:soft-refresh', softRefresh);
  utils.bus.off('memories:timeline:hard-refresh', refresh);
  resetState();
  state.value = 0;
});

const routeHasNative = computed((): boolean => {
  return routeIsBase.value && nativex.has();
});

const isMonthView = computed((): boolean => {
  if (route.query.sort === 'timeline') return false;
  if (route.query.sort === 'album') return true;
  return (
    (config.sort_album_month && (routeIsAlbums.value || routeIsAlbumShare.value)) ||
    (config.sort_folder_month && routeIsFolders.value)
  );
});

/** Nothing to show here */
const empty = computed((): boolean => {
  return !list.value.length && !dtmContent.value;
});

/** Show the empty content box and hide the scrollbar */
const showEmpty = computed((): boolean => {
  return !loading.value && empty.value;
});

/** Whether to allow swipe refresh */
const allowSwipe = computed((): boolean => {
  return !loading.value && currentScroll.value === 0;
});

async function routeChange(to: RouteLocationNormalized, from?: RouteLocationNormalized) {
  // Always do a hard refresh if the path changes
  if (from?.path !== to.path) {
    await refresh();

    // Focus on the recycler (e.g. after navigation click)
    // Unless the user is typing in the search box
    if (!document.activeElement?.closest?.('.memories-searchbar')) {
      recycler.value?.$el.focus();
    }
  }

  // Do a soft refresh if the query changes
  else if (JSON.stringify(from.query) !== JSON.stringify(to.query)) {
    await softRefreshSync();
  }

  // Check if viewer is supposed to be open
  if (from?.hash !== to.hash && !_m.viewer.isOpen && (utils.fragment.viewer || utils.fragment.day)) {
    // Open viewer
    const [dayidStr, key] = utils.fragment.viewer?.args || utils.fragment.day!.args;
    const dayid = parseInt(dayidStr);
    if (isNaN(dayid) || !key) return;

    // Get day
    const day = heads.value.get(dayid)?.day;
    if (day && !day.detail) {
      const stateVal = state.value;
      await fetchDay(dayid, true);
      if (stateVal !== state.value) return;
    }

    // Find photo
    const photo = day?.detail?.find((p) => p.key === key);
    if (!photo) return;

    // Scroll to photo if initializing, or if just a scroll change.
    if (!from || utils.fragment.day) {
      const index = list.value.findIndex((r) => r.day.dayid === dayid && r.photos?.includes(photo));
      if (index !== -1) {
        recycler.value?.scrollToItem(index);
      }
    }

    if (utils.fragment.viewer) {
      // Pass a getter-backed state so the viewer always reads the
      // current collections, even after a refresh replaces them.
      _m.viewer.openDynamic(photo, {
        get list() {
          return list.value;
        },
        get heads() {
          return heads.value;
        },
      });
    } else if (utils.fragment.day) {
      utils.fragment.clear();
    }
  }
}

function updateLoading(delta: number): void {
  loading.value = Math.max(0, loading.value + delta);
}

function isMobile() {
  return containerSize[0] <= 768;
}

function isMobileLayout() {
  return containerSize[0] <= 600;
}

function allowBreakout() {
  return windowDims.width <= 600 && !config.square_thumbs;
}

/** Create new state */
async function createState() {
  // Wait for one tick before doing anything
  await nextTick();

  // Fit to window
  recomputeSizes();

  // Timeline recycler init
  recycler.value?.$el.addEventListener('scroll', scrollPositionChange, { passive: true });

  // Get data
  await fetchDays();
}

/** Reset all state */
async function resetState() {
  selectionManager.value?.clear();
  scrollerManager.value?.reset();
  loading.value = 0;
  list.value = [];
  dtmContent.value = false;
  heads.value = new Map();
  currentStart.value = 0;
  currentEnd.value = 0;
  state.value = Math.random();
  loadedDays.clear();
  sizedDays.clear();
  fetchDayQueue = [];
  window.clearTimeout(fetchDayTimer ?? 0);
  resizeTimer.clear();
}

/** Recreate everything */
async function refresh() {
  await resetState();
  await createState();
}

/**
 * Fetch and re-process days (debounced call)
 * Debouncing is necessary due to a large number of calls, e.g.
 * when changing the configuration
 */
function softRefresh() {
  _softRefreshInternal(false);
}

/** Fetch and re-process days (sync can be awaited) */
async function softRefreshSync() {
  await _softRefreshInternal(true);
}

/**
 * Fetch and re-process days (can be awaited if sync).
 * Do not pass this function as a callback directly.
 */
async function _softRefreshInternal(sync: boolean) {
  selectionManager.value?.clear();
  fetchDayQueue = []; // reset queue

  // Fetch days
  if (sync) {
    await fetchDays(true);
  } else {
    softRefreshTimer.set(() => fetchDays(true), 30);
  }
}

/** Do resize after some time */
function handleResizeWithDelay() {
  resizeTimer.set(recomputeSizes, 100);
}

/** Recompute static sizes of containers */
function recomputeSizes() {
  // Get the container element
  const containerEl = container.value?.$el;
  if (!containerEl) return;

  // Size of outer container
  const height = containerEl.clientHeight;
  const width = containerEl.clientWidth;
  containerSize = [width, height];

  // Scroller spans the container height
  scrollerHeight.value = height;

  // Static top matter to exclude from recycler height
  const tmHeight = topmatter.value?.$el?.clientHeight ?? 0;

  // Recycler height
  const recyclerEl = recycler.value!;
  const targetHeight = height - tmHeight - 4;
  const targetWidth = isMobile() ? width : width - 40;
  const heightChanged = recyclerEl.$el.clientHeight !== targetHeight;
  const widthChanged = rowWidth !== targetWidth;

  if (heightChanged) {
    recyclerEl.$el.style.height = `${targetHeight}px`;
  }

  if (widthChanged) {
    rowWidth = targetWidth;
  }

  if (!heightChanged && !widthChanged) {
    // If the target size is the same, nothing else could have
    // possibly changed either, so just skip
    return;
  }

  if (isMobileLayout()) {
    // Mobile
    numCols = Math.max(3, Math.floor(rowWidth / MOBILE_ROW_HEIGHT));
    rowHeight = Math.floor(rowWidth / numCols);
  } else {
    // Desktop
    if (config.square_thumbs) {
      numCols = Math.max(3, Math.floor(rowWidth / DESKTOP_ROW_HEIGHT));
      rowHeight = Math.floor(rowWidth / numCols);
    } else {
      // As a heuristic, assume all images are 4:3 landscape
      rowHeight = DESKTOP_ROW_HEIGHT;
      numCols = Math.ceil(rowWidth / ((rowHeight * 4) / 3));
    }
  }

  // Reflow if there are elements (this isn't an init call)
  // An init call reaches here when the top matter size changes
  if (list.value.length > 0) {
    // At this point we're sure the size has changed, so we need
    // to invalidate everything related to sizes
    sizedDays.clear();
    scrollerManager.value?.adjust();

    // Explicitly request a scroll event
    loadScrollView();
  }
}

/**
 * Triggered when position of scroll change.
 * This does NOT indicate the items have changed, only that
 * the pixel position of the recycler has changed.
 */
function scrollPositionChange(event?: Event) {
  scrollerManager.value?.recyclerScrolled(event ?? null);
}

/** Trigger when recycler view changes (for callback) */
function scrollChangeRecycler(startIndex: number, endIndex: number) {
  return scrollChange(startIndex, endIndex);
}

/** Trigger when recycler view changes to refresh view */
function scrollChange(startIndex: number, endIndex: number, force = false) {
  if (startIndex === currentStart.value && endIndex === currentEnd.value && !force) {
    return;
  }

  // Reset placeholder state for rows including padding
  const rmin = Math.max(0, startIndex - ROW_NUM_LPAD);
  const rmax = Math.min(list.value.length, endIndex + ROW_NUM_LPAD);
  for (let i = rmin; i < rmax; i++) {
    const row = list.value[i];
    if (!row) {
      continue;
    }

    // Initialize photos and add placeholders
    if (row.pct && !row.photos?.length) {
      row.photos = new Array(row.pct);
      for (let j = 0; j < row.pct; j++) {
        // Any row that has placeholders has ONLY placeholders
        // so we can calculate the display width
        row.photos[j] = {
          flag: c.FLAG_PLACEHOLDER,
          fileid: Math.random(),
          dayid: row.dayId,
          dispW: utils.roundHalf(rowWidth / numCols),
          dispX: utils.roundHalf((j * rowWidth) / numCols),
          dispH: rowHeight,
          dispY: 0,
        };
      }
    }

    // No need for the fake count regardless of what happened above
    delete row.pct;
  }

  // We only need to debounce loads if the user is dragging the scrollbar
  const scrolling = scrollerManager.value?.interacting;

  // Make sure we don't do this too often
  currentStart.value = startIndex;
  currentEnd.value = endIndex;

  // Check if we can do this immediately
  const delay = force || !scrolling ? 0 : SCROLL_LOAD_DELAY;

  // Debounce; only execute the newest call after delay
  scrollChangeTimer.set(loadScrollView, delay);
}

/** Load image data for given view (index based) */
function loadScrollView(startIndex?: number, endIndex?: number) {
  // Default values if not defined
  startIndex ??= currentStart.value;
  endIndex ??= currentEnd.value;

  // Check if any side needs a padding.
  // Whenever less than half rows are loaded, we need to pad with full
  // rows on that side. This ensures we have minimal reflows.
  const rmin = Math.max(0, startIndex - ROW_NUM_LPAD / 2);
  const rmax = Math.min(list.value.length - 1, endIndex + ROW_NUM_LPAD / 2);
  const notsized = (r: IRow) => r && !sizedDays.has(r.dayId);

  // Check at the start
  if (list.value.slice(rmin, startIndex).some(notsized)) {
    startIndex -= ROW_NUM_LPAD;
  }

  // Check at the end
  if (list.value.slice(endIndex + 1, rmax + 1).some(notsized)) {
    endIndex += ROW_NUM_LPAD;
  }

  // Make sure start and end valid
  startIndex = Math.max(0, startIndex);
  endIndex = Math.min(list.value.length - 1, endIndex);

  // Fetch all visible days
  for (let i = startIndex; i <= endIndex; i++) {
    const item = list.value[i];
    if (!item) continue;
    if (loadedDays.has(item.dayId)) {
      if (!sizedDays.has(item.dayId)) {
        // Just quietly reflow without refetching
        processDay(item.dayId, item.day.detail!);
      }
      continue;
    }

    fetchDay(item.dayId);
  }
}

/** Get query string for API calls */
function getQuery() {
  const query: { [key in DaysFilterType]?: string } = {};
  const set = (filter: DaysFilterType, value: string = '1') => (query[filter] = value);

  // Favorites
  if (routeIsFavorites.value) {
    set(DaysFilterType.FAVORITES);
  }

  // Videos
  if (routeIsVideos.value) {
    set(DaysFilterType.VIDEOS);
  }

  // Panoramas
  if (routeIsPanoramas.value) {
    set(DaysFilterType.PANO);
  }

  // Folder
  if (routeIsFolders.value || routeIsFolderShare.value) {
    const path = utils.getFolderRoutePath(config.folders_path);
    set(DaysFilterType.FOLDER, path);
    if (route.query.recursive) {
      set(DaysFilterType.RECURSIVE);
    }
  }

  // Archive
  if (routeIsArchive.value) {
    set(DaysFilterType.ARCHIVE);
  }

  // Albums
  const user = utils.routeParamToString(route.params.user);
  const name = utils.routeParamToString(route.params.name);
  if (routeIsAlbums.value) {
    if (!user || !name) {
      throw new Error('Invalid album route');
    }
    set(DaysFilterType.ALBUM, `${user}/${name}`);
  }

  // People
  if (routeIsPeople.value) {
    if (!user || !name) {
      throw new Error('Invalid face route');
    }

    // name is "recognize" or "facerecognition"
    const filter = <DaysFilterType>route.name;
    set(filter, `${user}/${name}`);

    // Face rect
    if (config.show_face_rect || routeIsRecognizeUnassigned.value) {
      set(DaysFilterType.FACE_RECT);
    }
  }

  // Places
  if (routeIsPlaces.value) {
    if (name?.includes('-')) {
      const id = name.split('-', 1)[0];
      set(DaysFilterType.PLACE, id);
    } else if (name === c.PLACES_NULL) {
      set(DaysFilterType.PLACE, c.PLACES_NULL);
    } else {
      throw new Error('Invalid place route');
    }
  }

  // Tags
  if (routeIsTags.value) {
    if (!name) {
      throw new Error('Invalid tag route');
    }
    set(DaysFilterType.TAG, name);
  }

  // Map Bounds
  if (routeIsMap.value) {
    const bounds = route.query.b?.toString();
    if (!bounds) {
      throw new Error('Missing map bounds');
    }

    set(DaysFilterType.MAP_BOUNDS, bounds);
  }

  // Month view
  if (isMonthView.value) {
    set(DaysFilterType.MONTH_VIEW);
    set(DaysFilterType.REVERSE);
  }

  return query;
}

/** Fetch timeline main call */
async function fetchDays(noCache = false) {
  // Awaiting this is important because the folders must render
  // before the timeline to prevent glitches
  try {
    updateLoading(1);
    const stateVal = state.value;
    const res = await dtm.value?.refresh();
    if (state.value !== stateVal) return;
    dtmContent.value = res ?? false;
  } finally {
    updateLoading(-1);
  }

  // Lens search mode serves a fake day, not the days API
  if (routeIsSearch.value) {
    return await fetchLensSearch();
  }

  // Get URL an cache identifier
  let url: string;
  try {
    url = API.Q(API.DAYS(), getQuery());
  } catch (err) {
    // Likely invalid route; just quit doing anything
    return;
  }

  // URL for cached data
  const cacheUrl = <string>route.name + url;

  // Try cache first
  let cache: IDay[] | null = null;

  try {
    updateLoading(1);
    const startState = state.value;

    let data: IDay[] = [];
    if (routeIsThisDay.value) {
      data = await dav.getOnThisDayData();
    } else if (dav.isSingleItem()) {
      data = await dav.getSingleItemData();
      setTimeout(() => _m.viewer.open(data[0]!.detail![0]), 0);
    } else {
      // Try the cache
      if (!noCache || routeHasNative.value) {
        try {
          cache = await utils.getCachedData(cacheUrl);

          // On native, treat a missing remote cache as empty.
          if (routeHasNative.value) {
            cache = nativex.mergeDays(cache ?? [], await nativex.getLocalDays());
          }

          if (cache) {
            await processDays(cache, true);
            updateLoading(-1);
          }
        } catch {
          console.warn(`Failed to process days cache: ${cacheUrl}`);
          cache = null;
        }
      }

      // Get from network
      const res = await axios.get<IDay[]>(url);
      if (res.status !== 200) throw res; // don't cache this
      data = res.data;
    }

    // Put back into cache
    utils.cacheData(cacheUrl, data);

    // Extend with native days
    if (routeHasNative.value) {
      data = nativex.mergeDays(data, await nativex.getLocalDays());
    }

    // Make sure we're still on the same page
    if (state.value !== startState) return;
    await processDays(data, false);
  } catch (e: any) {
    if (!utils.isNetworkError(e)) {
      showError(e?.response?.data?.message ?? e.message);
      console.error(e);
    }
  } finally {
    // If cache is set here, loading was already decremented
    if (!cache) updateLoading(-1);
  }
}

/**
 * Process the data for days call including folders
 * @param data Days data
 * @param cache Whether the data was from cache
 */
async function processDays(data: IDay[], cache: boolean) {
  if (!data || !state.value) return;

  const newList: IRow[] = [];
  const newHeads: Map<number, IHeadRow> = new Map();

  // Store the preloads in a separate map.
  // This is required since otherwise the inner detail objects
  // do not become reactive (which happens only after assignment).
  const preloads = new Map<number, IPhoto[]>();

  let prevDay: IDay | null = null;
  for (const day of data) {
    // Initialization
    day.rows = [];

    // Nothing here
    if (day.count === 0) {
      continue;
    }

    // Store the preloads
    if (day.detail) {
      preloads.set(day.dayid, day.detail);
      delete day.detail;
    }

    // Create header for this day
    const head: IHeadRow = {
      id: `${day.dayid}-head`,
      num: -1,
      size: 40,
      type: 0, // head
      selected: false,
      dayId: day.dayid,
      day: day,
    };

    // Mark month view to change the header title
    if (isMonthView.value) head.ismonth = true;

    // Special headers
    if (routeIsThisDay.value && (!prevDay || Math.abs(prevDay.dayid - day.dayid) > 30)) {
      // thisday view with new year title
      head.size = 67;
      head.super = utils.getFromNowStr(utils.dayIdToDate(day.dayid), { padding: 10 });
    }

    // Add header to list
    newHeads.set(day.dayid, head);
    newList.push(head);

    // Dummy rows for placeholders
    let nrows = Math.ceil(day.count / numCols);

    // Check if already loaded - we can learn
    const prevRows = heads.value.get(day.dayid)?.day?.rows;
    nrows = prevRows?.length || nrows;

    // Add rows
    for (let i = 0; i < nrows; i++) {
      const row = addRow(day);
      newList.push(row);

      // Add placeholder count
      const leftNum = day.count - i * numCols;
      row.pct = Math.max(0, Math.min(numCols, leftNum));
      row.photos = [];

      // Learn from existing row
      if (prevRows && i < prevRows.length && !prevRows[i].pct) {
        row.size = prevRows[i].size;
        row.photos = prevRows[i].photos;
        delete row.pct;
      }
    }

    // Continue processing
    prevDay = day;
  }

  // Store globally
  list.value = newList;
  heads.value = newHeads;
  loadedDays.clear();
  sizedDays.clear();

  // Mark if the data was from cache
  daysIsCache = cache;

  // Iterate the preload map
  // Now the inner detail objects are reactive
  for (let [dayId, photos] of preloads) {
    photos = preprocessDay(dayId, photos);
    processDay(dayId, photos);
  }

  // Notify parent components about stats
  emit('daysLoaded', {
    count: data.reduce((acc, day) => acc + day.count, 0),
  });

  // Fix view height variable
  await scrollerManager.value?.reflow();
  scrollPositionChange();

  // Trigger a view refresh. This will load any new placeholders too.
  scrollChange(currentStart.value, currentEnd.value, true);
}

/** API url for Day call */
function getDayUrl(dayIds: number[]) {
  const query = getQuery();

  // If any day in the fetch list has local images we need to fetch
  // the remote hidden images for the merging to happen correctly
  if (routeHasNative.value) {
    if (dayIds.some((id) => heads.value.get(id)?.day?.haslocal)) {
      query[DaysFilterType.HIDDEN] = '1';
    }
  }

  return API.Q(API.DAY(dayIds.join(',')), query);
}

/** Fetch image data for one dayId */
async function fetchDay(dayId: number, now = false) {
  if (!now && loadedDays.has(dayId)) return;

  // Get head to ensure the day exists / is valid
  const head = heads.value.get(dayId);
  if (!head) return;

  // Do this in advance to prevent duplicate requests
  loadedDays.add(dayId);
  sizedDays.add(dayId);

  // Look for cache
  const cacheUrl = getDayUrl([dayId]);
  try {
    let cache = await utils.getCachedData<IPhoto[]>(cacheUrl);
    utils.applyAuids(cache);

    // On native, treat a missing remote cache as empty.
    if (routeHasNative.value && head.day?.haslocal) {
      nativex.mergeDay((cache ??= []), await nativex.getLocalDay(dayId));
    }

    // Process the cache
    if (cache) {
      cache = preprocessDay(dayId, cache);

      // If this is a cached response and the list is not, then we don't
      // want to take any destructive actions like removing a day.
      //  1. If a day is removed then it will not be fetched again
      //  2. But it probably does exist on the server
      //  3. Since days could be fetched, the user probably is connected
      if (!daysIsCache && !cache.length) {
        throw new Error('Skipping empty cache because view is fresh');
      }

      processDay(dayId, cache);
    }
  } catch (e) {
    console.warn(`Failed or skipped processing day cache: ${cacheUrl}`, e);
  }

  // Aggregate fetch requests
  fetchDayQueue.push(dayId);

  // If the queue has gotten large enough, just expire immediately
  // This is to prevent a large number of requests from being queued
  now ||= fetchDayQueue.length >= 16;
  now ||= fetchDayQueue.reduce((sum, dayId) => sum + (heads.value.get(dayId)?.day?.count ?? 0), 0) > 256;

  // Process immediately
  if (now) return await fetchDayExpire();

  // Defer for aggregation
  fetchDayTimer ??= window.setTimeout(() => {
    fetchDayTimer = null;
    fetchDayExpire();
  }, 150);
}

async function fetchDayExpire() {
  if (fetchDayQueue.length === 0) return;

  // Map of dayId to photos
  const dayIds = fetchDayQueue;
  const dayMap = new Map<number, IPhoto[]>();
  for (const dayId of dayIds) dayMap.set(dayId, []);

  // Construct URL
  const url = getDayUrl(dayIds);
  fetchDayQueue = [];

  try {
    const startState = state.value;
    const [data, isCached] = await (async () => {
      try {
        const res = await axios.get<IPhoto[]>(url);
        if (res.status !== 200) throw res;
        return [res.data, false];
      } catch (e: any) {
        // Force a cache read with nativex to update local.
        if (nativex.has()) {
          const res = await Promise.all(
            dayIds.map(async (dayId) => {
              const cacheUrl = getDayUrl([dayId]);
              const data = await utils.getCachedData<IPhoto[]>(cacheUrl);
              return data ?? [];
            }),
          );
          return [res.flat(), true];
        }
        throw e;
      }
    })();
    utils.applyAuids(data);

    // Check if the state has changed
    if (state.value !== startState || getDayUrl(dayIds) !== url) {
      return;
    }

    // Bin the data into separate days
    // It is already sorted in dayid DESC
    for (const photo of data) {
      dayMap.get(photo.dayid)?.push(photo);
    }

    // Store cache asynchronously if this was not cache.
    // Do this regardless of whether the state has
    // changed since the data is already fetched
    //
    // These loops cannot be combined because processDay
    // creates circular references which cannot be stringified
    //
    // The day is cached regardless of whether it is empty.
    // Empty days might be fetched e.g. on NativeX. In this case,
    // empty caches will not be processed if the view is fresh.
    if (!isCached) {
      for (const [dayId, photos] of dayMap) {
        utils.cacheData(getDayUrl([dayId]), photos);
      }
    }

    // Get local images if we are running in native environment.
    // Get them all together for each day here.
    if (routeHasNative.value) {
      const promises = Array.from(dayMap.entries())
        .filter(([dayId, photos]) => {
          // Extra hooks for each day
          // Well this doesn't really belong here ...
          nativex.processFreshServerDay(dayId, photos);

          // Only process days that have local images further
          return heads.value.get(dayId)?.day?.haslocal;
        })
        .map(async ([dayId, photos]) => {
          nativex.mergeDay(photos, await nativex.getLocalDay(dayId));
        });
      if (promises.length) await Promise.all(promises);
    }

    // Process each day as needed
    for (let [dayId, photos] of dayMap) {
      // Remove files marked as hidden
      photos = preprocessDay(dayId, photos);

      // Check if the response has any delta
      const head = heads.value.get(dayId);
      if (head?.day?.detail?.length === photos.length) {
        // Goes over the day and checks each photo including
        // the order with the current list. If anything changes,
        // we reprocess everything; otherwise just copy over
        // newer props that are reactive.
        const isSame = head.day.detail.every((curr, i) => {
          const now = photos[i];
          if (curr.fileid === now.fileid && curr.etag === now.etag) {
            // copy over any properties that might have changed
            // this way we don't need to iterate again for this
            utils.convertFlags(now);

            // copy over flags
            utils.copyPhotoFlags(now, curr);

            // keep merged local copy up to date
            curr.local_photo = now.local_photo;

            // keep deduped copies up to date (#1299)
            curr.dups = now.dups;

            return true;
          }

          return false;
        });

        // Skip this entire day since nothing changed
        if (isSame) continue;
      }

      // Pass ahead
      processDay(dayId, photos);
    }
  } catch (e) {
    if (!utils.isNetworkError(e)) {
      showError(t('memories', 'Failed to load some photos'));
      console.error(e);
    }
  }
}

/**
 * Preprocess items from day response.
 * This should be called on all responses before doing any checks.
 *
 * 1. Removes hidden files from the response
 * 2. Performs stacking, e.g. for JPG+NEF pairs
 */
function preprocessDay(dayId: number, data: IPhoto[]): IPhoto[] {
  if (!data?.length) return [];

  // Set of basenames without extension
  const res1: IPhoto[] = [];
  const toStack = new Map<string, IPhoto[]>();
  const auids = new Map<string, IPhoto>();

  // First pass -- remove hidden and prepare
  for (const photo of data) {
    // Skip hidden files
    if (photo.ishidden) continue;
    if (photo.basename?.startsWith('.')) continue;

    // Remember hidden duplicates for bulk actions (#1299)
    if (config.dedup_identical && photo.auid) {
      const prev = auids.get(photo.auid);
      if (prev) {
        (prev.dups ??= []).push(photo);
        continue;
      }
      auids.set(photo.auid, photo);
    }

    // Add to first pass result
    res1.push(photo);

    // Remove extension
    let basename = utils.removeExtension(photo.basename ?? String());
    if (!basename) continue; // huh?

    // Store RAW files for stacking
    if (config.stack_raw_files && photo.mimetype === c.MIME_RAW) {
      // Google's RAW naming is inconsistent and retarded.
      // We will handle this on a case-to-case basis, unless there's
      // a strong argument to always take the basename only upto the
      // first dot.
      // https://github.com/pulsejet/memories/issues/927
      // https://github.com/pulsejet/memories/issues/1006
      if (basename.includes('.ORIGINAL')) {
        // Consider basename only upto the first dot
        basename = basename.split('.', 1)[0];
      }

      // Store the RAW file for stacking with the usable basename
      const files = toStack.get(basename);
      if (!files) {
        toStack.set(basename, [photo]);
      } else {
        files.push(photo);
      }
    }
  }

  // Skip second pass unless needed
  if (!toStack.size) return res1;

  // File IDs that have been stacked
  const stacked = new Set<IPhoto>();

  // Second pass -- stack files
  for (const photo of res1) {
    if (photo.mimetype === c.MIME_RAW) {
      continue; // never stack over RAW
    }

    // Check if any RAW files can be stacked
    const basename = utils.removeExtension(photo.basename ?? String());
    const files = toStack.get(basename) ?? [];

    // If a second dot is present in the name, then split till the first dot
    // https://github.com/pulsejet/memories/issues/927
    // https://github.com/pulsejet/memories/issues/1006
    if (basename.includes('.')) {
      // Consider basename only upto the first dot
      const subname = basename.split('.', 1)[0];
      files.push(...(toStack.get(subname) ?? []));
    }

    if (!files.length) continue;

    // Stack on top of this file
    photo.stackraw = files;

    // Mark as stacked
    files.forEach((f) => stacked.add(f));
  }

  // Remove files that were stacked
  const res2 = res1.filter((p) => !stacked.has(p));

  return res2;
}

/**
 * Process items from day response.
 */
function processDay(dayId: number, data: IPhoto[]) {
  if (!data || !state.value) return;

  const head = heads.value.get(dayId);
  if (!head) return;

  const day = head.day;
  loadedDays.add(dayId);
  sizedDays.add(dayId);

  // Convert server flags to bitflags
  data.forEach(utils.convertFlags);

  // Set and make reactive
  day.count = data.length;
  day.detail = data;
  day.rows ??= [];

  // Reset rows including placeholders
  for (const row of day.rows) {
    row.photos = [];
  }

  // Force all to square
  const squareMode = isMobileLayout() || config.square_thumbs;

  // Create justified layout with correct params
  const justify = getLayout(
    day.detail.map((p) => ({
      width: p.w || rowHeight,
      height: p.h || rowHeight,
      forceSquare: false,
    })),
    {
      rowWidth: rowWidth,
      rowHeight: rowHeight,
      squareMode: squareMode,
      numCols: numCols,
      allowBreakout: allowBreakout(),
      seed: dayId,
    },
  );

  // Check if some rows were added
  let addedRows: IRow[] = [];

  // Recycler scroll top
  let scrollTop = recycler.value!.$el.scrollTop;
  let needAdjust = false;

  // Get index and Y position of header in O(n)
  let headIdx = 0;
  let headY = 0;
  for (const row of list.value) {
    if (row === head) break;
    headIdx++;
    headY += row.size;
  }
  let rowIdx = headIdx + 1;
  let rowY = headY + head.size;

  // Duplicate detection, e.g. for face rects
  const seen = new Map<number, number>();

  // Previous justified row
  let prevJustifyTop = justify[0]?.top ?? 0;

  // Add all rows
  let dataIdx = 0;
  while (dataIdx < data.length) {
    // Check if we ran out of rows
    if (rowIdx >= list.value.length || list.value[rowIdx].type === 0) {
      const newRow = addRow(day);
      addedRows.push(newRow);
      list.value.splice(rowIdx, 0, newRow);

      // Scroll down if new row is above the current visible position
      if (rowY < scrollTop) {
        scrollTop += newRow.size;
      }
      needAdjust = true;
    }

    // Get row
    const row = list.value[rowIdx];

    // Go to the next row
    const jbox = justify[dataIdx];
    if (jbox.top !== prevJustifyTop) {
      prevJustifyTop = jbox.top;
      rowIdx++;
      rowY += row.size;
      continue;
    }

    // Set row height
    const jH = utils.roundHalf(jbox.rowHeight || jbox.height);
    const delta = jH - row.size;
    // If the difference is too small, it's not worth risking an adjustment
    // especially on square layouts on mobile. Also don't do this if animating.
    if (Math.abs(delta) > 0) {
      if (rowY < scrollTop) {
        scrollTop += delta;
      }
      needAdjust = true;
      row.size = jH;
    }

    // Add the photo to the row
    const photo = data[dataIdx];
    photo.d = day; // backref to day

    // Get aspect ratio
    const setPos = () => {
      photo.dispW = utils.roundHalf(jbox.width);
      photo.dispX = utils.roundHalf(jbox.left);
      photo.dispH = utils.roundHalf(jbox.height);
      photo.dispY = 0;
      photo.dispRowNum = row.num;
    };
    if (photo.dispW !== undefined) {
      // photo already displayed: animate
      window.setTimeout(setPos, 50);

      if (
        photo.dispRowNum !== undefined &&
        photo.dispRowNum !== row.num &&
        photo.dispRowNum >= 0 &&
        photo.dispRowNum < day.rows.length
      ) {
        // Row change animation
        const start = Math.min(photo.dispRowNum, row.num);
        const end = Math.max(photo.dispRowNum, row.num);
        const sizeDelta = day.rows.slice(start, end).reduce((acc, r) => {
          acc += r.size;
          return acc;
        }, 0);
        photo.dispY = sizeDelta * (photo.dispRowNum < row.num ? -1 : 1);
        photo.dispH = day.rows[photo.dispRowNum].size;
      }
    } else {
      setPos();
    }

    // Move to next index of photo
    dataIdx++;

    // Duplicate detection.
    // These may be valid, e.g. in face rects. All we need to have
    // is a unique Vue key for the v-for loop.
    // Some backends might provide a key, such as lens.
    if (!photo.key) {
      const key = photo.faceid || photo.fileid;
      const val = seen.get(key);
      if (val) {
        photo.key = `${key}-${val}`;
        seen.set(key, val + 1);
      } else {
        photo.key = `${key}`;
        seen.set(key, 1);
      }
    }

    // Add photo to row
    row.photos!.push(photo);
    delete row.pct;
  }

  // Restore selection day
  selectionManager.value?.restoreDay(day);

  // Rows that were removed
  const removedRows: IRow[] = [];
  let headRemoved = false;

  // No rows, splice everything including the header
  if (data.length === 0) {
    removedRows.push(...list.value.splice(headIdx, 1));
    rowIdx = headIdx - 1;
    headRemoved = true;
    heads.value.delete(dayId);
  }

  // Get rid of any extra rows
  let spliceCount = 0;
  for (let i = rowIdx + 1; i < list.value.length && list.value[i].type !== 0; i++) {
    spliceCount++;
  }
  if (spliceCount > 0) {
    removedRows.push(...list.value.splice(rowIdx + 1, spliceCount));
  }

  // Update size delta for removed rows and remove from day
  for (const row of removedRows) {
    needAdjust = true;

    // Scroll up if if above visible range
    if (rowY < scrollTop) {
      scrollTop -= row.size;
    }

    // Remove from day
    const idx = day.rows.indexOf(row);
    if (idx >= 0) day.rows.splice(idx, 1);
  }

  // This will be true even if the head is being spliced
  // because one row is always removed in that case
  if (needAdjust) {
    if (headRemoved) {
      // If the head was removed, we need a reflow,
      // or adjust isn't going to work right
      scrollerManager.value?.reflow();
    } else {
      // Otherwise just adjust the ticks
      scrollerManager.value?.adjust();
    }

    // Scroll to new position
    recycler.value!.$el.scrollTop = scrollTop;
  }
}

/** Add and get a new blank photos row */
function addRow(day: IDay): IPhotoRow {
  // Make sure rows exists
  day.rows ??= [];

  // Create new row
  const row: IPhotoRow = {
    id: `${day.dayid}-${day.rows.length}`,
    num: day.rows.length,
    photos: [],
    type: 1, // photos
    size: rowHeight,
    dayId: day.dayid,
    day: day,
  };

  // Add to day
  day.rows.push(row);

  return row;
}

/**
 * Delete elements from main view with some animation
 *
 * This is also going to update day.detail for you and make
 * a call to processDay so just pass it the list of ids to
 * delete and the days that were updated.
 *
 * @param delPhotos photos to delete
 */
async function deleteFromViewWithAnimation(delPhotos: IPhoto[]) {
  // Only keep photos with day
  delPhotos = delPhotos.filter((p) => p?.d);
  if (delPhotos.length === 0) return;

  // Get all days that need to be updated
  const updatedDays = new Set<IDay>(delPhotos.map((p) => p.d!));
  const delPhotosSet = new Set(delPhotos);

  // Animate the deletion
  for (const photo of delPhotos) {
    photo.flag |= c.FLAG_LEAVING;
  }

  // wait for 200ms
  await new Promise((resolve) => setTimeout(resolve, 200));

  // clear selection at this point
  selectionManager.value?.deselect(delPhotos);

  // Reflow all touched days
  for (const day of updatedDays) {
    const newDetail = day.detail?.filter((p) => !delPhotosSet.has(p));
    processDay(day.dayid, newDetail!);
  }
}

/** Fetch lens search results into top + month days */
async function fetchLensSearch() {
  const query = lens.routeQueryText(route.query.q).trim();

  try {
    updateLoading(1);
    const stateVal = state.value;
    const days = await lens.getLensSearchDays(query);
    if (state.value !== stateVal) return;
    await processDays(days, false);

    // Title the top day; month days get month titles via head.ismonth
    for (const day of days) {
      lens.markSearchHead(day, heads.value.get(day.dayid));
    }
  } catch (e: any) {
    if (!utils.isNetworkError(e)) {
      showError(e?.response?.data?.message ?? e.message);
      console.error(e);
    }
  } finally {
    updateLoading(-1);
  }
}
</script>

<style lang="scss" scoped>
/** Main view */
.container {
  height: 100%;
  width: 100%;
  overflow: hidden;
  position: relative;
  display: flex;
  flex-direction: column;

  @media (max-width: 768px) {
    // Get rid of padding on img-outer (1px on mobile)
    // Also need to make sure we don't end up with a scrollbar -- see below
    margin-left: -1px;
    width: calc(100% + 3px); // 1px extra here for sub-pixel rounding
  }
}

.recycler {
  will-change: scroll-position;
  contain: strict;
  height: 300px;
  width: 100%;
  transition: opacity 0.2s ease-in-out;

  :deep(.vue-recycle-scroller__slot) {
    contain: content;
  }

  :deep(.vue-recycle-scroller__item-wrapper) {
    contain: strict;
  }

  :deep(.vue-recycle-scroller__item-view) {
    contain: layout style;
  }

  &.empty {
    opacity: 0;
    transition: none;
    height: 0 !important;
  }

  &:focus {
    outline: none;
  }
}

.recycler .photo {
  contain: strict;
  display: block;
  cursor: pointer;
  height: 100%;
  transition:
    width 0.2s ease-in-out,
    height 0.2s ease-in-out,
    transform 0.2s ease-in-out; // reflow
}

/** Dynamic top matter */
.recycler-before {
  width: 100%;
}
</style>
