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
    <TimelineTopOverlay
      ref="topOverlay"
      :heads="heads"
      :container="refs.container?.$el"
      :recycler="refs.recycler?.$el"
    />

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
      :updateInterval="100"
      @update="scrollChangeRecycler"
    >
      <template #before>
        <!-- Dynamic top matter, e.g. album or view name -->
        <div class="recycler-before" ref="recyclerBefore">
          <!-- Gap for mobile header -->
          <div class="mobile-header-top-gap"></div>

          <!-- Route-specific top matter -->
          <DynamicTopMatter ref="dtm" @load="refs.scrollerManager.adjust()" />
        </div>
      </template>

      <template v-slot="{ item, index }">
        <RowHead v-if="item.type === 0" :item="item" @click="refs.selectionManager.selectHead(item)" />

        <template v-else>
          <Photo
            class="photo top-left"
            v-for="photo of item.photos"
            :key="photo.key"
            :style="{
              height: `${photo.dispH}px`,
              width: `${photo.dispW}px`,
              transform: `translate(${photo.dispX}px, ${photo.dispY}px)`,
            }"
            :data="photo"
            :day="item.day"
            @select="refs.selectionManager.clickSelectionIcon(photo, $event, index)"
            @pointerdown="refs.selectionManager.clickPhoto(photo, $event, index)"
            @touchstart="refs.selectionManager.touchstartPhoto(photo, $event, index)"
            @touchend="refs.selectionManager.touchendPhoto(photo, $event, index)"
            @touchmove="refs.selectionManager.touchmovePhoto(photo, $event, index)"
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
      :recycler="refs.recycler"
      :recyclerBefore="refs.recyclerBefore"
      @interactend="loadScrollView"
      @scroll="
        currentScroll = $event.current;
        refs.topOverlay?.refresh();
      "
    />

    <SelectionManager
      ref="selectionManager"
      :heads="heads"
      :rows="list"
      :isreverse="isMonthView"
      :recycler="refs.recycler?.$el"
      :scrollerManager="refs.scrollerManager"
      @updateLoading="updateLoading"
    />
  </SwipeRefresh>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Route } from 'vue-router';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import { getLayout } from '@services/layout';

import UserConfig from '@mixins/UserConfig';
import RowHead from '@components/frame/RowHead.vue';
import Photo from '@components/frame/Photo.vue';
import ScrollerManager from '@components/ScrollerManager.vue';
import SelectionManager from '@components/SelectionManager.vue';
import Viewer from '@components/viewer/Viewer.vue';
import SwipeRefresh from './SwipeRefresh.vue';

import EmptyContent from '@components/top-matter/EmptyContent.vue';
import TopMatter from '@components/top-matter/TopMatter.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';
import TimelineTopOverlay from '@components/top-matter/TimelineTopOverlay.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import { API, DaysFilterType } from '@services/API';

import type { IDay, IHeadRow, IPhoto, IRow } from '@typings';

const SCROLL_LOAD_DELAY = 100; // Delay in loading data when scrolling
const DESKTOP_ROW_HEIGHT = 200; // Height of row on desktop
const MOBILE_ROW_HEIGHT = 120; // Approx row height on mobile
const ROW_NUM_LPAD = 16; // Number of rows to load before and after viewport

export default defineComponent({
  name: 'Timeline',

  components: {
    RowHead,
    Photo,
    EmptyContent,
    TopMatter,
    DynamicTopMatter,
    TimelineTopOverlay,
    SelectionManager,
    ScrollerManager,
    Viewer,
    SwipeRefresh,
  },

  mixins: [UserConfig],

  emits: {
    daysLoaded: (stats: { count: number }) => true,
  },

  data: () => ({
    /** Loading days response */
    loading: 0,
    /** Main list of rows */
    list: [] as IRow[],
    /** Dynamic top matter has standalone content */
    dtmContent: false,
    /** Computed number of columns */
    numCols: 0,
    /** Ordered header rows for dayId key  */
    heads: new Map<number, IHeadRow>(),
    /** Current list (days response) was loaded from cache */
    daysIsCache: false,

    /** Size of outer container [w, h] */
    containerSize: [0, 0] as [number, number],
    /** Computed row height */
    rowHeight: 100,
    /** Computed row width */
    rowWidth: 100,

    /** Current start index */
    currentStart: 0,
    /** Current end index */
    currentEnd: 0,
    /** Current physical scroll position */
    currentScroll: 0,
    /** Resize observer on the outer container */
    resizeObserver: null as ResizeObserver | null,
    /** Resizing timer */
    resizeTimer: null as number | null,
    /** Height of the scroller */
    scrollerHeight: 100,

    /** Set of dayIds for which images loaded */
    loadedDays: new Set<number>(),
    /** Set of dayIds for which image size is calculated */
    sizedDays: new Set<number>(),
    /** Days to load in the next call */
    fetchDayQueue: [] as number[],
    /** Timer to load day call */
    fetchDayTimer: null as number | null,

    /** State for request cancellations */
    state: Math.random(),
  }),

  mounted() {
    // Trigger initial state load
    this.routeChange(this.$route);

    // Start resize observer on container
    if (this.refs.container?.$el) {
      this.resizeObserver = new ResizeObserver(() => this.handleResizeWithDelay());
      this.resizeObserver.observe(this.refs.container.$el);
    }
  },

  unmounted() {
    this.resizeObserver?.disconnect();
  },

  watch: {
    async $route(to: Route, from?: Route) {
      await this.routeChange(to, from);
    },
  },

  created() {
    utils.bus.on('memories:user-config-changed', this.softRefresh);
    utils.bus.on('files:file:created', this.softRefresh);
    utils.bus.on('memories:window:resize', this.handleResizeWithDelay);
    utils.bus.on('memories:timeline:fetch-day', this.fetchDay);
    utils.bus.on('memories:timeline:deleted', this.deleteFromViewWithAnimation);
    utils.bus.on('memories:timeline:soft-refresh', this.softRefresh);
    utils.bus.on('memories:timeline:hard-refresh', this.refresh);
  },

  beforeDestroy() {
    utils.bus.off('memories:user-config-changed', this.softRefresh);
    utils.bus.off('files:file:created', this.softRefresh);
    utils.bus.off('memories:window:resize', this.handleResizeWithDelay);
    utils.bus.off('memories:timeline:fetch-day', this.fetchDay);
    utils.bus.off('memories:timeline:deleted', this.deleteFromViewWithAnimation);
    utils.bus.off('memories:timeline:soft-refresh', this.softRefresh);
    utils.bus.off('memories:timeline:hard-refresh', this.refresh);
    this.resetState();
    this.state = 0;
  },

  computed: {
    refs() {
      return this.$refs as {
        container?: InstanceType<typeof SwipeRefresh>;
        topmatter?: InstanceType<typeof TopMatter>;
        dtm?: InstanceType<typeof DynamicTopMatter>;
        topOverlay?: InstanceType<typeof TimelineTopOverlay>;
        recycler?: VueRecyclerType;
        recyclerBefore?: HTMLDivElement;
        selectionManager: InstanceType<typeof SelectionManager>;
        scrollerManager: InstanceType<typeof ScrollerManager>;
      };
    },

    routeHasNative(): boolean {
      return this.routeIsBase && nativex.has();
    },

    isMonthView(): boolean {
      if (this.$route.query.sort === 'timeline') return false;
      if (this.$route.query.sort === 'album') return true;
      return (
        (this.config.sort_album_month && (this.routeIsAlbums || this.routeIsAlbumShare)) ||
        (this.config.sort_folder_month && this.routeIsFolders)
      );
    },

    /** Nothing to show here */
    empty(): boolean {
      return !this.list.length && !this.dtmContent;
    },

    /** Show the empty content box and hide the scrollbar */
    showEmpty(): boolean {
      return !this.loading && this.empty;
    },

    /** Whether to allow swipe refresh */
    allowSwipe(): boolean {
      return !this.loading && this.currentScroll === 0;
    },
  },

  methods: {
    async routeChange(to: Route, from?: Route) {
      // Always do a hard refresh if the path changes
      if (from?.path !== to.path) {
        await this.refresh();

        // Focus on the recycler (e.g. after navigation click)
        this.refs.recycler?.$el.focus();
      }

      // Do a soft refresh if the query changes
      else if (JSON.stringify(from.query) !== JSON.stringify(to.query)) {
        await this.softRefreshSync();
      }

      // Check if viewer is supposed to be open
      if (from?.hash !== to.hash && !_m.viewer.isOpen && utils.fragment.viewer) {
        // Open viewer
        const [dayidStr, key] = utils.fragment.viewer.args;
        const dayid = parseInt(dayidStr);
        if (isNaN(dayid) || !key) return;

        // Get day
        const day = this.heads.get(dayid)?.day;
        if (day && !day.detail) {
          const state = this.state;
          await this.fetchDay(dayid, true);
          if (state !== this.state) return;
        }

        // Find photo
        const photo = day?.detail?.find((p) => p.key === key);
        if (!photo) return;

        // Scroll to photo if initializing
        if (!from) {
          const index = this.list.findIndex((r) => r.day.dayid === dayid && r.photos?.includes(photo));
          if (index !== -1) {
            this.refs.recycler?.scrollToItem(index);
          }
        }

        _m.viewer.openDynamic(photo, this);
      }
    },

    updateLoading(delta: number): void {
      this.loading = Math.max(0, this.loading + delta);
    },

    isMobile() {
      return this.containerSize[0] <= 768;
    },

    isMobileLayout() {
      return this.containerSize[0] <= 600;
    },

    allowBreakout() {
      return _m.window.innerWidth <= 600 && !this.config.square_thumbs;
    },

    /** Create new state */
    async createState() {
      // Wait for one tick before doing anything
      await this.$nextTick();

      // Fit to window
      this.recomputeSizes();

      // Timeline recycler init
      this.refs.recycler?.$el.addEventListener('scroll', this.scrollPositionChange, { passive: true });

      // Get data
      await this.fetchDays();
    },

    /** Reset all state */
    async resetState() {
      this.refs.selectionManager.clear();
      this.refs.scrollerManager.reset();
      this.loading = 0;
      this.list = [];
      this.dtmContent = false;
      this.heads = new Map();
      this.currentStart = 0;
      this.currentEnd = 0;
      this.state = Math.random();
      this.loadedDays.clear();
      this.sizedDays.clear();
      this.fetchDayQueue = [];
      window.clearTimeout(this.fetchDayTimer ?? 0);
      window.clearTimeout(this.resizeTimer ?? 0);
    },

    /** Recreate everything */
    async refresh() {
      await this.resetState();
      await this.createState();
    },

    /**
     * Fetch and re-process days (debounced call)
     * Debouncing is necessary due to a large number of calls, e.g.
     * when changing the configuration
     */
    softRefresh() {
      this._softRefreshInternal(false);
    },

    /** Fetch and re-process days (sync can be awaited) */
    async softRefreshSync() {
      await this._softRefreshInternal(true);
    },

    /**
     * Fetch and re-process days (can be awaited if sync).
     * Do not pass this function as a callback directly.
     */
    async _softRefreshInternal(sync: boolean) {
      this.refs.selectionManager.clear();
      this.fetchDayQueue = []; // reset queue

      // Fetch days
      if (sync) {
        await this.fetchDays(true);
      } else {
        utils.setRenewingTimeout(this, '_softRefreshInternalTimer', () => this.fetchDays(true), 30);
      }
    },

    /** Do resize after some time */
    handleResizeWithDelay() {
      utils.setRenewingTimeout(this, 'resizeTimer', this.recomputeSizes, 100);
    },

    /** Recompute static sizes of containers */
    recomputeSizes() {
      // Get the container element
      const container = this.refs.container?.$el;
      if (!container) return;

      // Size of outer container
      const height = container.clientHeight;
      const width = container.clientWidth;
      this.containerSize = [width, height];

      // Scroller spans the container height
      this.scrollerHeight = height;

      // Static top matter to exclude from recycler height
      const topmatter = this.refs.topmatter;
      const tmHeight = topmatter?.$el?.clientHeight || 0;

      // Recycler height
      const recycler = this.refs.recycler!;
      const targetHeight = height - tmHeight - 4;
      const targetWidth = this.isMobile() ? width : width - 40;
      const heightChanged = recycler.$el.clientHeight !== targetHeight;
      const widthChanged = this.rowWidth !== targetWidth;

      if (heightChanged) {
        recycler.$el.style.height = targetHeight + 'px';
      }

      if (widthChanged) {
        this.rowWidth = targetWidth;
      }

      if (!heightChanged && !widthChanged) {
        // If the target size is the same, nothing else could have
        // possibly changed either, so just skip
        return;
      }

      if (this.isMobileLayout()) {
        // Mobile
        this.numCols = Math.max(3, Math.floor(this.rowWidth / MOBILE_ROW_HEIGHT));
        this.rowHeight = Math.floor(this.rowWidth / this.numCols);
      } else {
        // Desktop
        if (this.config.square_thumbs) {
          this.numCols = Math.max(3, Math.floor(this.rowWidth / DESKTOP_ROW_HEIGHT));
          this.rowHeight = Math.floor(this.rowWidth / this.numCols);
        } else {
          // As a heuristic, assume all images are 4:3 landscape
          this.rowHeight = DESKTOP_ROW_HEIGHT;
          this.numCols = Math.ceil(this.rowWidth / ((this.rowHeight * 4) / 3));
        }
      }

      // Reflow if there are elements (this isn't an init call)
      // An init call reaches here when the top matter size changes
      if (this.list.length > 0) {
        // At this point we're sure the size has changed, so we need
        // to invalidate everything related to sizes
        this.sizedDays.clear();
        this.refs.scrollerManager.adjust();

        // Explicitly request a scroll event
        this.loadScrollView();
      }
    },

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: Event) {
      this.refs.scrollerManager.recyclerScrolled(event ?? null);
    },

    /** Trigger when recycler view changes (for callback) */
    scrollChangeRecycler(startIndex: number, endIndex: number) {
      return this.scrollChange(startIndex, endIndex);
    },

    /** Trigger when recycler view changes to refresh view */
    scrollChange(startIndex: number, endIndex: number, force = false) {
      if (startIndex === this.currentStart && endIndex === this.currentEnd && !force) {
        return;
      }

      // Reset placeholder state for rows including padding
      const rmin = Math.max(0, startIndex - ROW_NUM_LPAD);
      const rmax = Math.min(this.list.length, endIndex + ROW_NUM_LPAD);
      for (let i = rmin; i < rmax; i++) {
        const row = this.list[i];
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
              flag: this.c.FLAG_PLACEHOLDER,
              fileid: Math.random(),
              dayid: row.dayId,
              dispW: utils.roundHalf(this.rowWidth / this.numCols),
              dispX: utils.roundHalf((j * this.rowWidth) / this.numCols),
              dispH: this.rowHeight,
              dispY: 0,
            };
          }
        }

        // No need for the fake count regardless of what happened above
        delete row.pct;
      }

      // We only need to debounce loads if the user is dragging the scrollbar
      const scrolling = this.refs.scrollerManager.interacting;

      // Make sure we don't do this too often
      this.currentStart = startIndex;
      this.currentEnd = endIndex;

      // Check if we can do this immediately
      const delay = force || !scrolling ? 0 : SCROLL_LOAD_DELAY;

      // Debounce; only execute the newest call after delay
      utils.setRenewingTimeout(this, '_scrollChangeTimer', this.loadScrollView, delay);
    },

    /** Load image data for given view (index based) */
    loadScrollView(startIndex?: number, endIndex?: number) {
      // Default values if not defined
      startIndex ??= this.currentStart;
      endIndex ??= this.currentEnd;

      // Check if any side needs a padding.
      // Whenever less than half rows are loaded, we need to pad with full
      // rows on that side. This ensures we have minimal reflows.
      const rmin = Math.max(0, startIndex - ROW_NUM_LPAD / 2);
      const rmax = Math.min(this.list.length - 1, endIndex + ROW_NUM_LPAD / 2);
      const notsized = (r: IRow) => r && !this.sizedDays.has(r.dayId);

      // Check at the start
      if (this.list.slice(rmin, startIndex).some(notsized)) {
        startIndex -= ROW_NUM_LPAD;
      }

      // Check at the end
      if (this.list.slice(endIndex + 1, rmax + 1).some(notsized)) {
        endIndex += ROW_NUM_LPAD;
      }

      // Make sure start and end valid
      startIndex = Math.max(0, startIndex);
      endIndex = Math.min(this.list.length - 1, endIndex);

      // Fetch all visible days
      for (let i = startIndex; i <= endIndex; i++) {
        const item = this.list[i];
        if (!item) continue;
        if (this.loadedDays.has(item.dayId)) {
          if (!this.sizedDays.has(item.dayId)) {
            // Just quietly reflow without refetching
            this.processDay(item.dayId, item.day.detail!);
          }
          continue;
        }

        this.fetchDay(item.dayId);
      }
    },

    /** Get query string for API calls */
    getQuery() {
      const query: { [key in DaysFilterType]?: string } = {};
      const set = (filter: DaysFilterType, value: string = '1') => (query[filter] = value);

      // Favorites
      if (this.routeIsFavorites) {
        set(DaysFilterType.FAVORITES);
      }

      // Videos
      if (this.routeIsVideos) {
        set(DaysFilterType.VIDEOS);
      }

      // Folder
      if (this.routeIsFolders || this.routeIsFolderShare) {
        const path = utils.getFolderRoutePath(this.config.folders_path);
        set(DaysFilterType.FOLDER, path);
        if (this.$route.query.recursive) {
          set(DaysFilterType.RECURSIVE);
        }
      }

      // Archive
      if (this.routeIsArchive) {
        set(DaysFilterType.ARCHIVE);
      }

      // Albums
      const { user, name } = this.$route.params;
      if (this.routeIsAlbums) {
        if (!user || !name) {
          throw new Error('Invalid album route');
        }
        set(DaysFilterType.ALBUM, `${user}/${name}`);
      }

      // People
      if (this.routeIsPeople) {
        if (!user || !name) {
          throw new Error('Invalid face route');
        }

        // name is "recognize" or "facerecognition"
        const filter = <DaysFilterType>this.$route.name;
        set(filter, `${user}/${name}`);

        // Face rect
        if (this.config.show_face_rect || this.routeIsRecognizeUnassigned) {
          set(DaysFilterType.FACE_RECT);
        }
      }

      // Places
      if (this.routeIsPlaces) {
        if (name?.includes('-')) {
          const id = name.split('-', 1)[0];
          set(DaysFilterType.PLACE, id);
        } else if (name === this.c.PLACES_NULL) {
          set(DaysFilterType.PLACE, this.c.PLACES_NULL);
        } else {
          throw new Error('Invalid place route');
        }
      }

      // Tags
      if (this.routeIsTags) {
        if (!name) {
          throw new Error('Invalid tag route');
        }
        set(DaysFilterType.TAG, name);
      }

      // Map Bounds
      if (this.routeIsMap) {
        const bounds = <string>this.$route.query.b;
        if (!bounds) {
          throw new Error('Missing map bounds');
        }

        set(DaysFilterType.MAP_BOUNDS, bounds);
      }

      // Month view
      if (this.isMonthView) {
        set(DaysFilterType.MONTH_VIEW);
        set(DaysFilterType.REVERSE);
      }

      return query;
    },

    /** Fetch timeline main call */
    async fetchDays(noCache = false) {
      // Awaiting this is important because the folders must render
      // before the timeline to prevent glitches
      try {
        this.updateLoading(1);
        const state = this.state;
        const res = await this.refs.dtm?.refresh();
        if (this.state !== state) return;
        this.dtmContent = res ?? false;
      } finally {
        this.updateLoading(-1);
      }

      // Get URL an cache identifier
      let url: string;
      try {
        url = API.Q(API.DAYS(), this.getQuery());
      } catch (err) {
        // Likely invalid route; just quit doing anything
        return;
      }

      // URL for cached data
      const cacheUrl = <string>this.$route.name + url;

      // Try cache first
      let cache: IDay[] | null = null;

      try {
        this.updateLoading(1);
        const startState = this.state;

        let data: IDay[] = [];
        if (this.routeIsThisDay) {
          data = await dav.getOnThisDayData();
        } else if (dav.isSingleItem()) {
          data = await dav.getSingleItemData();
          setTimeout(() => _m.viewer.open(data[0]!.detail![0]), 0);
        } else {
          // Try the cache
          if (!noCache) {
            try {
              if ((cache = await utils.getCachedData(cacheUrl))) {
                if (this.routeHasNative) {
                  cache = nativex.mergeDays(cache, await nativex.getLocalDays());
                }

                await this.processDays(cache, true);
                this.updateLoading(-1);
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
        if (this.routeHasNative) {
          data = nativex.mergeDays(data, await nativex.getLocalDays());
        }

        // Make sure we're still on the same page
        if (this.state !== startState) return;
        await this.processDays(data, false);
      } catch (e) {
        if (!utils.isNetworkError(e)) {
          showError(e?.response?.data?.message ?? e.message);
          console.error(e);
        }
      } finally {
        // If cache is set here, loading was already decremented
        if (!cache) this.updateLoading(-1);
      }
    },

    /**
     * Process the data for days call including folders
     * @param data Days data
     * @param cache Whether the data was from cache
     */
    async processDays(data: IDay[], cache: boolean) {
      if (!data || !this.state) return;

      const list: typeof this.list = [];
      const heads: typeof this.heads = new Map();

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
        if (this.isMonthView) head.ismonth = true;

        // Special headers
        if (this.routeIsThisDay && (!prevDay || Math.abs(prevDay.dayid - day.dayid) > 30)) {
          // thisday view with new year title
          head.size = 67;
          head.super = utils.getFromNowStr(utils.dayIdToDate(day.dayid), { padding: 10 });
        }

        // Add header to list
        heads.set(day.dayid, head);
        list.push(head);

        // Dummy rows for placeholders
        let nrows = Math.ceil(day.count / this.numCols);

        // Check if already loaded - we can learn
        const prevRows = this.heads.get(day.dayid)?.day?.rows;
        nrows = prevRows?.length || nrows;

        // Add rows
        for (let i = 0; i < nrows; i++) {
          const row = this.addRow(day);
          list.push(row);

          // Add placeholder count
          const leftNum = day.count - i * this.numCols;
          row.pct = Math.max(0, Math.min(this.numCols, leftNum));
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
      this.list = list;
      this.heads = heads;
      this.loadedDays.clear();
      this.sizedDays.clear();

      // Mark if the data was from cache
      this.daysIsCache = cache;

      // Iterate the preload map
      // Now the inner detail objects are reactive
      for (let [dayId, photos] of preloads) {
        photos = this.preprocessDay(dayId, photos);
        this.processDay(dayId, photos);
      }

      // Notify parent components about stats
      this.$emit('daysLoaded', {
        count: data.reduce((acc, day) => acc + day.count, 0),
      });

      // Fix view height variable
      await this.refs.scrollerManager.reflow();
      this.scrollPositionChange();

      // Trigger a view refresh. This will load any new placeholders too.
      this.scrollChange(this.currentStart, this.currentEnd, true);
    },

    /** API url for Day call */
    getDayUrl(dayIds: number[]) {
      const query = this.getQuery();

      // If any day in the fetch list has local images we need to fetch
      // the remote hidden images for the merging to happen correctly
      if (this.routeHasNative) {
        if (dayIds.some((id) => this.heads.get(id)?.day?.haslocal)) {
          query[DaysFilterType.HIDDEN] = '1';
        }
      }

      return API.Q(API.DAY(dayIds.join(',')), query);
    },

    /** Fetch image data for one dayId */
    async fetchDay(dayId: number, now = false) {
      if (!now && this.loadedDays.has(dayId)) return;

      // Get head to ensure the day exists / is valid
      const head = this.heads.get(dayId);
      if (!head) return;

      // Do this in advance to prevent duplicate requests
      this.loadedDays.add(dayId);
      this.sizedDays.add(dayId);

      // Look for cache
      const cacheUrl = this.getDayUrl([dayId]);
      try {
        let cache = await utils.getCachedData<IPhoto[]>(cacheUrl);
        if (cache) {
          // Cache only contains remote images; update from local too
          if (this.routeHasNative && head.day?.haslocal) {
            nativex.mergeDay(cache, await nativex.getLocalDay(dayId));
          }

          // Process the cache
          cache = this.preprocessDay(dayId, cache);

          // If this is a cached response and the list is not, then we don't
          // want to take any destructive actions like removing a day.
          //  1. If a day is removed then it will not be fetched again
          //  2. But it probably does exist on the server
          //  3. Since days could be fetched, the user probably is connected
          if (!this.daysIsCache && !cache.length) {
            throw new Error('Skipping empty cache because view is fresh');
          }

          this.processDay(dayId, cache);
        }
      } catch (e) {
        console.warn(`Failed or skipped processing day cache: ${cacheUrl}`, e);
      }

      // Aggregate fetch requests
      this.fetchDayQueue.push(dayId);

      // If the queue has gotten large enough, just expire immediately
      // This is to prevent a large number of requests from being queued
      now ||= this.fetchDayQueue.length >= 16;
      now ||= this.fetchDayQueue.reduce((sum, dayId) => sum + (this.heads.get(dayId)?.day?.count ?? 0), 0) > 256;

      // Process immediately
      if (now) return await this.fetchDayExpire();

      // Defer for aggregation
      this.fetchDayTimer ??= window.setTimeout(() => {
        this.fetchDayTimer = null;
        this.fetchDayExpire();
      }, 150);
    },

    async fetchDayExpire() {
      if (this.fetchDayQueue.length === 0) return;

      // Map of dayId to photos
      const dayIds = this.fetchDayQueue;
      const dayMap = new Map<number, IPhoto[]>();
      for (const dayId of dayIds) dayMap.set(dayId, []);

      // Construct URL
      const url = this.getDayUrl(dayIds);
      this.fetchDayQueue = [];

      try {
        const startState = this.state;
        const res = await axios.get<IPhoto[]>(url);
        if (res.status !== 200) throw res;
        const data = res.data;

        // Check if the state has changed
        if (this.state !== startState || this.getDayUrl(dayIds) !== url) {
          return;
        }

        // Bin the data into separate days
        // It is already sorted in dayid DESC
        for (const photo of data) {
          dayMap.get(photo.dayid)?.push(photo);
        }

        // Store cache asynchronously
        // Do this regardless of whether the state has
        // changed since the data is already fetched
        //
        // These loops cannot be combined because processDay
        // creates circular references which cannot be stringified
        //
        // The day is cached regardless of whether it is empty.
        // Empty days might be fetched e.g. on NativeX. In this case,
        // empty caches will not be processed if the view is fresh.
        for (const [dayId, photos] of dayMap) {
          utils.cacheData(this.getDayUrl([dayId]), photos);
        }

        // Get local images if we are running in native environment.
        // Get them all together for each day here.
        if (this.routeHasNative) {
          const promises = Array.from(dayMap.entries())
            .filter(([dayId, photos]) => {
              // Extra hooks for each day
              // Well this doesn't really belong here ...
              nativex.processFreshServerDay(dayId, photos);

              // Only process days that have local images further
              return this.heads.get(dayId)?.day?.haslocal;
            })
            .map(async ([dayId, photos]) => {
              nativex.mergeDay(photos, await nativex.getLocalDay(dayId));
            });
          if (promises.length) await Promise.all(promises);
        }

        // Process each day as needed
        for (let [dayId, photos] of dayMap) {
          // Remove files marked as hidden
          photos = this.preprocessDay(dayId, photos);

          // Check if the response has any delta
          const head = this.heads.get(dayId);
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

                return true;
              }

              return false;
            });

            // Skip this entire day since nothing changed
            if (isSame) continue;
          }

          // Pass ahead
          this.processDay(dayId, photos);
        }
      } catch (e) {
        if (!utils.isNetworkError(e)) {
          showError(this.t('memories', 'Failed to load some photos'));
          console.error(e);
        }
      }
    },

    /**
     * Preprocess items from day response.
     * This should be called on all responses before doing any checks.
     *
     * 1. Removes hidden files from the response
     * 2. Performs stacking, e.g. for JPG+NEF pairs
     */
    preprocessDay(dayId: number, data: IPhoto[]): IPhoto[] {
      if (!data?.length) return [];

      // Set of basenames without extension
      const res1: IPhoto[] = [];
      const toStack = new Map<string, IPhoto[]>();
      const auids = new Set<string>();

      // First pass -- remove hidden and prepare
      for (const photo of data) {
        // Skip hidden files
        if (photo.ishidden) continue;
        if (photo.basename?.startsWith('.')) continue;

        // Skip identical duplicates
        if (this.config.dedup_identical && photo.auid) {
          if (auids.has(photo.auid)) continue;
          auids.add(photo.auid);
        }

        // Add to first pass result
        res1.push(photo);

        // Remove extension
        let basename = utils.removeExtension(photo.basename ?? String());
        if (!basename) continue; // huh?

        // Store RAW files for stacking
        if (this.config.stack_raw_files && photo.mimetype === this.c.MIME_RAW) {
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
        if (photo.mimetype === this.c.MIME_RAW) {
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
    },

    /**
     * Process items from day response.
     */
    processDay(dayId: number, data: IPhoto[]) {
      if (!data || !this.state) return;

      const head = this.heads.get(dayId);
      if (!head) return;

      const day = head.day;
      this.loadedDays.add(dayId);
      this.sizedDays.add(dayId);

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
      const squareMode = this.isMobileLayout() || this.config.square_thumbs;

      // Create justified layout with correct params
      const justify = getLayout(
        day.detail.map((p) => ({
          width: p.w || this.rowHeight,
          height: p.h || this.rowHeight,
          forceSquare: false,
        })),
        {
          rowWidth: this.rowWidth,
          rowHeight: this.rowHeight,
          squareMode: squareMode,
          numCols: this.numCols,
          allowBreakout: this.allowBreakout(),
          seed: dayId,
        },
      );

      // Check if some rows were added
      let addedRows: IRow[] = [];

      // Recycler scroll top
      let scrollTop = this.refs.recycler!.$el.scrollTop;
      let needAdjust = false;

      // Get index and Y position of header in O(n)
      let headIdx = 0;
      let headY = 0;
      for (const row of this.list) {
        if (row === head) break;
        headIdx++;
        headY += row.size;
      }
      let rowIdx = headIdx + 1;
      let rowY = headY + head.size;

      // Duplicate detection, e.g. for face rects
      const seen = new Map<number, number>();

      // Previous justified row
      let prevJustifyTop = justify[0]?.top || 0;

      // Add all rows
      let dataIdx = 0;
      while (dataIdx < data.length) {
        // Check if we ran out of rows
        if (rowIdx >= this.list.length || this.list[rowIdx].type === 0) {
          const newRow = this.addRow(day);
          addedRows.push(newRow);
          this.list.splice(rowIdx, 0, newRow);

          // Scroll down if new row is above the current visible position
          if (rowY < scrollTop) {
            scrollTop += newRow.size;
          }
          needAdjust = true;
        }

        // Get row
        const row = this.list[rowIdx];

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
        const key = photo.faceid || photo.fileid;
        const val = seen.get(key);
        if (val) {
          photo.key = `${key}-${val}`;
          seen.set(key, val + 1);
        } else {
          photo.key = `${key}`;
          seen.set(key, 1);
        }

        // Add photo to row
        row.photos!.push(photo);
        delete row.pct;
      }

      // Restore selection day
      this.refs.selectionManager.restoreDay(day);

      // Rows that were removed
      const removedRows: IRow[] = [];
      let headRemoved = false;

      // No rows, splice everything including the header
      if (data.length === 0) {
        removedRows.push(...this.list.splice(headIdx, 1));
        rowIdx = headIdx - 1;
        headRemoved = true;
        this.heads.delete(dayId);
      }

      // Get rid of any extra rows
      let spliceCount = 0;
      for (let i = rowIdx + 1; i < this.list.length && this.list[i].type !== 0; i++) {
        spliceCount++;
      }
      if (spliceCount > 0) {
        removedRows.push(...this.list.splice(rowIdx + 1, spliceCount));
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
          this.refs.scrollerManager.reflow();
        } else {
          // Otherwise just adjust the ticks
          this.refs.scrollerManager.adjust();
        }

        // Scroll to new position
        this.refs.recycler!.$el.scrollTop = scrollTop;
      }
    },

    /** Add and get a new blank photos row */
    addRow(day: IDay): IRow {
      // Make sure rows exists
      day.rows ??= [];

      // Create new row
      const row: IRow = {
        id: `${day.dayid}-${day.rows.length}`,
        num: day.rows.length,
        photos: [],
        type: 1, // photos
        size: this.rowHeight,
        dayId: day.dayid,
        day: day,
      };

      // Add to day
      day.rows.push(row);

      return row;
    },

    /**
     * Delete elements from main view with some animation
     *
     * This is also going to update day.detail for you and make
     * a call to processDay so just pass it the list of ids to
     * delete and the days that were updated.
     *
     * @param delPhotos photos to delete
     */
    async deleteFromViewWithAnimation(delPhotos: IPhoto[]) {
      // Only keep photos with day
      delPhotos = delPhotos.filter((p) => p?.d);
      if (delPhotos.length === 0) return;

      // Get all days that need to be updatd
      const updatedDays = new Set<IDay>(delPhotos.map((p) => p.d!));
      const delPhotosSet = new Set(delPhotos);

      // Animate the deletion
      for (const photo of delPhotos) {
        photo.flag |= this.c.FLAG_LEAVING;
      }

      // wait for 200ms
      await new Promise((resolve) => setTimeout(resolve, 200));

      // clear selection at this point
      this.refs.selectionManager.deselect(delPhotos);

      // Reflow all touched days
      for (const day of updatedDays) {
        const newDetail = day.detail?.filter((p) => !delPhotosSet.has(p));
        this.processDay(day.dayid, newDetail!);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
/** Main view */
.container {
  height: 100%;
  width: 100%;
  overflow: hidden;
  position: relative;

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

  :deep .vue-recycle-scroller__slot {
    contain: content;
  }

  :deep .vue-recycle-scroller__item-wrapper {
    contain: strict;
  }

  :deep .vue-recycle-scroller__item-view {
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
