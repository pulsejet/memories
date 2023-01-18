<template>
  <div
    class="container"
    ref="container"
    :class="{ 'icon-loading': loading > 0 }"
  >
    <!-- Static top matter -->
    <TopMatter ref="topmatter" />

    <!-- No content found and nothing is loading -->
    <NcEmptyContent
      title="Nothing to show here"
      :description="emptyViewDescription"
      v-if="loading === 0 && list.length === 0"
    >
      <template #icon>
        <PeopleIcon v-if="routeIsPeople" />
        <ArchiveIcon v-else-if="routeIsArchive" />
        <ImageMultipleIcon v-else />
      </template>
    </NcEmptyContent>

    <!-- Main recycler view for rows -->
    <RecycleScroller
      ref="recycler"
      class="recycler"
      :class="{ empty: list.length === 0 }"
      :items="list"
      :emit-update="true"
      :buffer="800"
      :skipHover="true"
      key-field="id"
      size-field="size"
      type-field="type"
      :updateInterval="100"
      @update="scrollChange"
      @resize="handleResizeWithDelay"
    >
      <template #before>
        <!-- Show dynamic top matter, name of the view -->
        <div class="recycler-before" ref="recyclerBefore">
          <div class="text" v-show="!$refs.topmatter.type && list.length > 0">
            {{ viewName }}
          </div>

          <OnThisDay
            v-if="routeIsBase"
            :key="config_timelinePath"
            :viewer="$refs.viewer"
            @load="scrollerManager.adjust()"
          >
          </OnThisDay>
        </div>
      </template>

      <template v-slot="{ item, index }">
        <div
          v-if="item.type === 0"
          class="head-row"
          :class="{ selected: item.selected }"
          :style="{ height: item.size + 'px' }"
          :key="item.id"
        >
          <div class="super" v-if="item.super !== undefined">
            {{ item.super }}
          </div>
          <div class="main" @click="selectionManager.selectHead(item)">
            <CheckCircle :size="20" class="select" v-if="item.name" />
            <span class="name"> {{ item.name || getHeadName(item) }} </span>
          </div>
        </div>

        <template v-else>
          <div
            class="photo"
            v-for="photo of item.photos"
            :key="photo.key"
            :style="{
              height: photo.dispH + 'px',
              width: photo.dispW + 'px',
              transform: `translate(${photo.dispX}px, ${photo.dispY}px`,
            }"
          >
            <Folder v-if="photo.flag & c.FLAG_IS_FOLDER" :data="photo" />

            <Tag v-else-if="photo.flag & c.FLAG_IS_TAG" :data="photo" />

            <Photo
              v-else
              :data="photo"
              :day="item.day"
              @select="selectionManager.selectPhoto"
              @pointerdown="selectionManager.clickPhoto(photo, $event, index)"
              @touchstart="
                selectionManager.touchstartPhoto(photo, $event, index)
              "
              @touchend="selectionManager.touchendPhoto(photo, $event, index)"
              @touchmove="selectionManager.touchmovePhoto(photo, $event, index)"
            />
          </div>
        </template>
      </template>
    </RecycleScroller>

    <!-- Managers -->
    <ScrollerManager
      ref="scrollerManager"
      :rows="list"
      :height="scrollerHeight"
      :recycler="$refs.recycler"
      :recyclerBefore="$refs.recyclerBefore"
    />

    <SelectionManager
      ref="selectionManager"
      :heads="heads"
      :rows="list"
      :isreverse="isMonthView"
      :recycler="$refs.recycler"
      @refresh="softRefresh"
      @delete="deleteFromViewWithAnimation"
      @updateLoading="updateLoading"
    />

    <Viewer
      ref="viewer"
      @deleted="deleteFromViewWithAnimation"
      @fetchDay="fetchDay"
      @updateLoading="updateLoading"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import axios from "@nextcloud/axios";
import { showError } from "@nextcloud/dialogs";
import { subscribe, unsubscribe } from "@nextcloud/event-bus";
import NcEmptyContent from "@nextcloud/vue/dist/Components/NcEmptyContent";

import { getLayout } from "../services/Layout";
import { IDay, IFolder, IHeadRow, IPhoto, IRow, IRowType } from "../types";
import Folder from "./frame/Folder.vue";
import Photo from "./frame/Photo.vue";
import Tag from "./frame/Tag.vue";
import ScrollerManager from "./ScrollerManager.vue";
import SelectionManager from "./SelectionManager.vue";
import Viewer from "./viewer/Viewer.vue";
import OnThisDay from "./top-matter/OnThisDay.vue";
import TopMatter from "./top-matter/TopMatter.vue";

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";

import PeopleIcon from "vue-material-design-icons/AccountMultiple.vue";
import CheckCircle from "vue-material-design-icons/CheckCircle.vue";
import ImageMultipleIcon from "vue-material-design-icons/ImageMultiple.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";
import { API } from "../services/API";

const SCROLL_LOAD_DELAY = 100; // Delay in loading data when scrolling
const DESKTOP_ROW_HEIGHT = 200; // Height of row on desktop
const MOBILE_ROW_HEIGHT = 120; // Approx row height on mobile

export default defineComponent({
  name: "Timeline",

  components: {
    Folder,
    Tag,
    Photo,
    TopMatter,
    OnThisDay,
    SelectionManager,
    ScrollerManager,
    Viewer,
    NcEmptyContent,

    CheckCircle,
    ArchiveIcon,
    PeopleIcon,
    ImageMultipleIcon,
  },

  data: () => ({
    /** Loading days response */
    loading: 0,
    /** Main list of rows */
    list: [] as IRow[],
    /** Computed number of columns */
    numCols: 0,
    /** Header rows for dayId key */
    heads: {} as { [dayid: number]: IHeadRow },

    /** Computed row height */
    rowHeight: 100,
    /** Computed row width */
    rowWidth: 100,

    /** Current start index */
    currentStart: 0,
    /** Current end index */
    currentEnd: 0,
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

    /** Selection manager component */
    selectionManager: null as InstanceType<typeof SelectionManager> & any,
    /** Scroller manager component */
    scrollerManager: null as InstanceType<typeof ScrollerManager> & any,
  }),

  mounted() {
    this.selectionManager = <any>this.$refs.selectionManager;
    this.scrollerManager = <any>this.$refs.scrollerManager;
    this.routeChange(this.$route);
  },

  watch: {
    async $route(to: any, from?: any) {
      await this.routeChange(to, from);
    },
  },

  beforeDestroy() {
    unsubscribe(this.config_eventName, this.softRefresh);
    unsubscribe("files:file:created", this.softRefresh);
    this.resetState();
  },

  created() {
    subscribe(this.config_eventName, this.softRefresh);
    subscribe("files:file:created", this.softRefresh);
    window.addEventListener("resize", this.handleResizeWithDelay);
  },

  destroyed() {
    window.removeEventListener("resize", this.handleResizeWithDelay);
  },

  computed: {
    routeIsBase(): boolean {
      return this.$route.name === "timeline";
    },
    routeIsPeople(): boolean {
      return ["recognize", "facerecognition"].includes(
        <string>this.$route.name
      );
    },
    routeIsArchive(): boolean {
      return this.$route.name === "archive";
    },
    isMonthView(): boolean {
      return (
        this.$route.name === "albums" || this.$route.name === "album-share"
      );
    },
    /** Get view name for dynamic top matter */
    viewName(): string {
      switch (this.$route.name) {
        case "timeline":
          return this.t("memories", "Your Timeline");
        case "favorites":
          return this.t("memories", "Favorites");
        case "recognize":
        case "facerecognition":
          return this.t("memories", "People");
        case "videos":
          return this.t("memories", "Videos");
        case "albums":
          return this.t("memories", "Albums");
        case "archive":
          return this.t("memories", "Archive");
        case "thisday":
          return this.t("memories", "On this day");
        case "tags":
          return this.t("memories", "Tags");
        default:
          return "";
      }
    },
    emptyViewDescription(): string {
      switch (this.$route.name) {
        case "facerecognition":
          if (this.config_facerecognitionEnabled)
            return this.t(
              "memories",
              "You will find your friends soon. Please, be patient."
            );
          else
            return this.t(
              "memories",
              "Face Recognition is disabled. Enable in settings to find your friends."
            );
        case "timeline":
        case "favorites":
        case "recognize":
        case "videos":
        case "albums":
        case "archive":
        case "thisday":
        case "tags":
        default:
          return "";
      }
    },
  },

  methods: {
    async routeChange(to: any, from?: any) {
      if (
        from?.path !== to.path ||
        JSON.stringify(from.query) !== JSON.stringify(to.query)
      ) {
        await this.refresh();
      }

      // The viewer might change the route immediately again
      await this.$nextTick();

      // Check if hash has changed
      const viewerIsOpen = (this.$refs.viewer as any).isOpen;
      if (
        from?.hash !== to.hash &&
        to.hash?.startsWith("#v") &&
        !viewerIsOpen
      ) {
        // Open viewer
        const parts = to.hash.split("/");
        if (parts.length !== 3) return;

        // Get params
        const dayid = parseInt(parts[1]);
        const key = parts[2];
        if (isNaN(dayid) || !key) return;

        // Get day
        const day = this.heads[dayid]?.day;
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
          const index = this.list.findIndex(
            (r) => r.day.dayid === dayid && r.photos?.includes(photo)
          );
          if (index !== -1) {
            (this.$refs.recycler as any).scrollToItem(index);
          }
        }

        (this.$refs.viewer as any).open(photo, this.list);
      } else if (
        from?.hash?.startsWith("#v") &&
        !to.hash?.startsWith("#v") &&
        viewerIsOpen
      ) {
        // Close viewer
        (this.$refs.viewer as any).close();
      }
    },
    updateLoading(delta: number) {
      this.loading += delta;
    },

    isMobile() {
      return globalThis.windowInnerWidth <= 768;
    },

    isMobileLayout() {
      return globalThis.windowInnerWidth <= 600;
    },

    allowBreakout() {
      return this.isMobileLayout() && !this.config_squareThumbs;
    },

    /** Create new state */
    async createState() {
      // Wait for one tick before doing anything
      await this.$nextTick();

      // Fit to window
      this.recomputeSizes();

      // Timeline recycler init
      (this.$refs.recycler as any).$el.addEventListener(
        "scroll",
        this.scrollPositionChange,
        { passive: true }
      );

      // Get data
      await this.fetchDays();
    },

    /** Reset all state */
    async resetState() {
      this.selectionManager.clearSelection();
      this.loading = 0;
      this.list = [];
      this.heads = {};
      this.currentStart = 0;
      this.currentEnd = 0;
      this.scrollerManager.reset();
      this.state = Math.random();
      this.loadedDays.clear();
      this.sizedDays.clear();
      this.fetchDayQueue = [];
      window.clearTimeout(this.fetchDayTimer);
      window.clearTimeout(this.resizeTimer);
    },

    /** Recreate everything */
    async refresh() {
      await this.resetState();
      await this.createState();
    },

    /** Re-process days */
    async softRefresh() {
      this.selectionManager.clearSelection();
      await this.fetchDays(true);
    },

    /** Do resize after some time */
    handleResizeWithDelay() {
      // Update global vars
      globalThis.windowInnerWidth = window.innerWidth;
      globalThis.windowInnerHeight = window.innerHeight;

      // Reflow after timer
      if (this.resizeTimer) {
        clearTimeout(this.resizeTimer);
      }
      this.resizeTimer = window.setTimeout(() => {
        this.recomputeSizes();
        this.resizeTimer = null;
      }, 100);
    },

    /** Recompute static sizes of containers */
    recomputeSizes() {
      // Size of outer container
      const e = this.$refs.container as Element;
      let height = e.clientHeight;
      const width = e.clientWidth;

      // Scroller spans the container height
      this.scrollerHeight = height;

      // Static top matter to exclude from recycler height
      const topmatter = this.$refs.topmatter as any;
      const tmHeight = topmatter.$el?.clientHeight || 0;

      // Recycler height
      const recycler = this.$refs.recycler as any;
      const targetHeight = height - tmHeight - 4;
      const targetWidth = this.isMobile() ? width : width - 40;
      const heightChanged = recycler.$el.clientHeight !== targetHeight;
      const widthChanged = this.rowWidth !== targetWidth;

      if (heightChanged) {
        recycler.$el.style.height = targetHeight + "px";
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
        this.numCols = Math.max(
          3,
          Math.floor(this.rowWidth / MOBILE_ROW_HEIGHT)
        );
        this.rowHeight = Math.floor(this.rowWidth / this.numCols);
      } else {
        // Desktop
        if (this.config_squareThumbs) {
          this.numCols = Math.max(
            3,
            Math.floor(this.rowWidth / DESKTOP_ROW_HEIGHT)
          );
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
        this.scrollerManager.adjust();

        // Explicitly request a scroll event
        this.loadScrollChanges(this.currentStart, this.currentEnd);
      }
    },

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: any) {
      this.scrollerManager.recyclerScrolled(event);
    },

    /** Trigger when recycler view changes */
    scrollChange(startIndex: number, endIndex: number) {
      if (startIndex === this.currentStart && endIndex === this.currentEnd) {
        return;
      }

      // Reset image state
      for (let i = startIndex; i < endIndex; i++) {
        const row = this.list[i];
        if (!row) {
          continue;
        }

        // Initialize photos and add placeholders
        if (row.pct && !row.photos.length) {
          row.photos = new Array(row.pct);
          for (let j = 0; j < row.pct; j++) {
            // Any row that has placeholders has ONLY placeholders
            // so we can calculate the display width
            row.photos[j] = {
              flag: this.c.FLAG_PLACEHOLDER,
              fileid: Math.random(),
              dispW: utils.roundHalf(this.rowWidth / this.numCols),
              dispX: utils.roundHalf((j * this.rowWidth) / this.numCols),
              dispH: this.rowHeight,
              dispY: 0,
            };
          }
          delete row.pct;
        }
      }

      // Check if this was requested by a refresh
      const force = this.currentEnd === -1;

      // Make sure we don't do this too often
      this.currentStart = startIndex;
      this.currentEnd = endIndex;

      // Check if this was requested specifically
      if (force) {
        this.loadScrollChanges(startIndex, endIndex);
        return;
      }

      setTimeout(() => {
        // Get the overlapping range between startIndex and
        // currentStart and endIndex and currentEnd.
        // This is the range of rows that we need to update.
        const start = Math.max(startIndex, this.currentStart);
        const end = Math.min(endIndex, this.currentEnd);

        if (end - start > 0) {
          this.loadScrollChanges(start, end);
        }
      }, SCROLL_LOAD_DELAY);
    },

    /** Load image data for given view */
    loadScrollChanges(startIndex: number, endIndex: number) {
      // Make sure start and end valid
      startIndex = Math.max(0, startIndex);
      endIndex = Math.min(this.list.length - 1, endIndex);

      // Fetch all visible days
      for (let i = startIndex; i <= endIndex; i++) {
        let item = this.list[i];
        if (!item) continue;
        if (this.loadedDays.has(item.dayId)) {
          if (!this.sizedDays.has(item.dayId)) {
            // Just quietly reflow without refetching
            this.processDay(item.dayId, item.day.detail);
          }
          continue;
        }

        this.fetchDay(item.dayId);
      }
    },

    /** Get query string for API calls */
    getQuery() {
      const query = new URLSearchParams();

      // Favorites
      if (this.$route.name === "favorites") {
        query.set("fav", "1");
      }

      // Videos
      if (this.$route.name === "videos") {
        query.set("vid", "1");
      }

      // Folder
      if (this.$route.name === "folders") {
        query.set("folder", utils.getFolderRoutePath(this.config_foldersPath));
        if (this.$route.query.recursive) {
          query.set("recursive", "1");
        }
      }

      // Archive
      if (this.$route.name === "archive") {
        query.set("archive", "1");
      }

      // People
      if (
        this.routeIsPeople &&
        this.$route.params.user &&
        this.$route.params.name
      ) {
        query.set(
          <string>this.$route.name, // "recognize" or "facerecognition"
          `${this.$route.params.user}/${this.$route.params.name}`
        );

        // Face rect
        if (this.config_showFaceRect) {
          query.set("facerect", "1");
        }
      }

      // Tags
      if (this.$route.name === "tags" && this.$route.params.name) {
        query.set("tag", <string>this.$route.params.name);
      }

      // Albums
      if (this.$route.name === "albums" && this.$route.params.name) {
        const user = <string>this.$route.params.user;
        const name = <string>this.$route.params.name;
        query.set("album", `${user}/${name}`);
      }

      // Month view
      if (this.isMonthView) {
        query.set("monthView", "1");
        query.set("reverse", "1");
      }

      return query;
    },

    /** Get name of header */
    getHeadName(head: IHeadRow) {
      // Check cache
      if (head.name) {
        return head.name;
      }

      // Special headers
      if (this.TagDayIDValueSet.has(head.dayId)) {
        return (head.name = "");
      }

      // Make date string
      // The reason this function is separate from processDays is
      // because this call is terribly slow even on desktop
      const dateTaken = utils.dayIdToDate(head.dayId);
      let name: string;
      if (this.isMonthView) {
        name = utils.getMonthDateStr(dateTaken);
      } else {
        name = utils.getLongDateStr(dateTaken, true);
      }

      // Cache and return
      head.name = name;
      return head.name;
    },

    /** Fetch timeline main call */
    async fetchDays(noCache = false) {
      const url = API.Q(API.DAYS(), this.getQuery());
      const cacheUrl = <string>this.$route.name + url;

      // Try cache first
      let cache: IDay[];

      // Make sure to refresh scroll later
      this.currentEnd = -1;

      try {
        this.loading++;
        const startState = this.state;

        let data: IDay[] = [];
        if (this.$route.name === "thisday") {
          data = await dav.getOnThisDayData();
        } else if (this.$route.name === "tags" && !this.$route.params.name) {
          data = await dav.getTagsData();
        } else if (this.routeIsPeople && !this.$route.params.name) {
          data = await dav.getPeopleData(this.$route.name as any);
        } else if (this.$route.name === "albums" && !this.$route.params.name) {
          data = await dav.getAlbumsData("3");
        } else {
          // Try the cache
          try {
            cache = noCache ? null : await utils.getCachedData(cacheUrl);
            if (cache) {
              await this.processDays(cache);
              this.loading--;
            }
          } catch {
            console.warn(`Failed to process days cache: ${cacheUrl}`);
            cache = null;
          }

          // Get from network
          const res = await axios.get<IDay[]>(url);
          if (res.status !== 200) throw res; // don't cache this
          data = res.data;
        }

        // Put back into cache
        utils.cacheData(cacheUrl, data);

        // Make sure we're still on the same page
        if (this.state !== startState) return;
        await this.processDays(data);
      } catch (err) {
        console.error(err);
        showError(err?.response?.data?.message || err.message);
      } finally {
        if (!cache) this.loading--;
      }
    },

    /** Process the data for days call including folders */
    async processDays(data: IDay[]) {
      const list: typeof this.list = [];
      const heads: typeof this.heads = {};

      // Store the preloads in a separate map.
      // This is required since otherwise the inner detail objects
      // do not become reactive (which happens only after assignment).
      const preloads: {
        [dayId: number]: {
          day: IDay;
          detail: IPhoto[];
        };
      } = {};

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
          preloads[day.dayid] = {
            day: day,
            detail: day.detail,
          };
          delete day.detail;
        }

        // Create header for this day
        const head: IHeadRow = {
          id: `${day.dayid}-head`,
          num: -1,
          size: 40,
          type: IRowType.HEAD,
          selected: false,
          dayId: day.dayid,
          day: day,
        };

        // Special headers
        if (this.TagDayIDValueSet.has(day.dayid)) {
          head.size = 10;
        } else if (
          this.$route.name === "thisday" &&
          (!prevDay || Math.abs(prevDay.dayid - day.dayid) > 30)
        ) {
          // thisday view with new year title
          head.size = 67;
          head.super = utils.getFromNowStr(utils.dayIdToDate(day.dayid));
        }

        // Add header to list
        heads[day.dayid] = head;
        list.push(head);

        // Dummy rows for placeholders
        let nrows = Math.ceil(day.count / this.numCols);

        // Check if already loaded - we can learn
        let prevRows: IRow[] | null = null;
        if (this.loadedDays.has(day.dayid)) {
          prevRows = this.heads[day.dayid]?.day.rows;
          nrows = prevRows?.length || nrows;
        }

        // Add rows
        for (let i = 0; i < nrows; i++) {
          const row = this.addRow(day);
          list.push(row);

          // Add placeholder count
          const leftNum = day.count - i * this.numCols;
          row.pct = leftNum > this.numCols ? this.numCols : leftNum;
          row.photos = [];

          // Learn from existing row
          if (prevRows && i < prevRows.length) {
            row.size = prevRows[i].size;
            row.photos = prevRows[i].photos;
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

      // Iterate the preload map
      // Now the inner detail objects are reactive
      for (const dayId in preloads) {
        this.processDay(Number(dayId), preloads[dayId].detail);
      }

      // Fix view height variable
      await this.scrollerManager.reflow();
      this.scrollPositionChange();
    },

    /** API url for Day call */
    getDayUrl(dayId: number | string) {
      return API.Q(API.DAY(dayId), this.getQuery());
    },

    /** Fetch image data for one dayId */
    async fetchDay(dayId: number, now = false) {
      const head = this.heads[dayId];
      if (!head) return;

      // Do this in advance to prevent duplicate requests
      this.loadedDays.add(dayId);
      this.sizedDays.add(dayId);

      // Look for cache
      const cacheUrl = this.getDayUrl(dayId);
      try {
        this.processDay(dayId, await utils.getCachedData(cacheUrl));
      } catch {
        console.warn(`Failed to process day cache: ${cacheUrl}`);
      }

      // Aggregate fetch requests
      this.fetchDayQueue.push(dayId);

      // Only single queries allowed for month vie
      if (now || this.isMonthView) {
        return this.fetchDayExpire();
      }

      // Defer for aggregation
      if (!this.fetchDayTimer) {
        this.fetchDayTimer = window.setTimeout(() => {
          this.fetchDayTimer = null;
          this.fetchDayExpire();
        }, 150);
      }
    },

    async fetchDayExpire() {
      if (this.fetchDayQueue.length === 0) return;

      // Construct URL
      const url = this.getDayUrl(this.fetchDayQueue.join(","));
      this.fetchDayQueue = [];

      try {
        const startState = this.state;
        const res = await axios.get<IPhoto[]>(url);
        if (res.status !== 200) throw res;
        const data = res.data;
        if (this.state !== startState) return;

        // Bin the data into separate days
        // It is already sorted in dayid DESC
        const dayMap = new Map<number, IPhoto[]>();
        for (const photo of data) {
          if (!dayMap.get(photo.dayid)) dayMap.set(photo.dayid, []);
          dayMap.get(photo.dayid).push(photo);
        }

        // Store cache asynchronously
        // Do this regardless of whether the state has
        // changed since the data is already fetched
        //
        // These loops cannot be combined because processDay
        // creates circular references which cannot be stringified
        for (const [dayId, photos] of dayMap) {
          utils.cacheData(this.getDayUrl(dayId), photos);
        }

        // Process each day as needed
        for (const [dayId, photos] of dayMap) {
          // Check if the response has any delta
          const head = this.heads[dayId];
          if (head.day.detail?.length) {
            if (
              head.day.detail.length === photos.length &&
              head.day.detail.every(
                (p, i) =>
                  p.fileid === photos[i].fileid &&
                  p.etag === photos[i].etag &&
                  p.filename === photos[i].filename
              )
            ) {
              continue;
            }
          }

          // Pass ahead
          this.processDay(dayId, photos);
        }
      } catch (e) {
        showError(this.t("memories", "Failed to load some photos"));
        console.error(e);
      }
    },

    /**
     * Process items from day response.
     *
     * @param dayId id of day
     * @param data photos
     */
    processDay(dayId: number, data: IPhoto[]) {
      if (!data) return;

      const head = this.heads[dayId];
      const day = head.day;
      this.loadedDays.add(dayId);
      this.sizedDays.add(dayId);

      // Convert server flags to bitflags
      data.forEach(utils.convertFlags);

      // Filter out items we don't want to show at all
      if (!this.config_showHidden && dayId === this.TagDayID.FOLDERS) {
        // Hidden folders and folders without previews
        data = data.filter(
          (p) =>
            !(
              p.flag & this.c.FLAG_IS_FOLDER &&
              ((<IFolder>p).name.startsWith(".") ||
                !(<IFolder>p).previews.length)
            )
        );
      }

      // Set and make reactive
      day.count = data.length;
      day.detail = data;

      // Reset rows including placeholders
      for (const row of head.day.rows || []) {
        row.photos = [];
      }

      // Force all to square
      const squareMode = this.isMobileLayout() || this.config_squareThumbs;

      // Create justified layout with correct params
      const justify = getLayout(
        day.detail.map((p) => {
          return {
            width: p.w || this.rowHeight,
            height: p.h || this.rowHeight,
            forceSquare: Boolean(
              (p.flag & this.c.FLAG_IS_FOLDER) | (p.flag & this.c.FLAG_IS_TAG)
            ),
          };
        }),
        {
          rowWidth: this.rowWidth,
          rowHeight: this.rowHeight,
          squareMode: squareMode,
          numCols: this.numCols,
          allowBreakout: this.allowBreakout(),
          seed: dayId,
        }
      );

      // Check if some rows were added
      let addedRows: IRow[] = [];

      // Recycler scroll top
      let scrollTop = (<any>this.$refs.recycler).$el.scrollTop;
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
        if (
          rowIdx >= this.list.length ||
          this.list[rowIdx].type === IRowType.HEAD
        ) {
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
        if (seen.has(photo.fileid)) {
          const val = seen.get(photo.fileid);
          photo.key = `${photo.fileid}-${val}`;
          seen.set(photo.fileid, val + 1);
        } else {
          photo.key = `${photo.fileid}`;
          seen.set(photo.fileid, 1);
        }

        // Add photo to row
        row.photos.push(photo);
      }

      // Restore selection day
      this.selectionManager.restoreDay(day);

      // Rows that were removed
      const removedRows: IRow[] = [];
      let headRemoved = false;

      // No rows, splice everything including the header
      if (data.length === 0) {
        removedRows.push(...this.list.splice(headIdx, 1));
        rowIdx = headIdx - 1;
        headRemoved = true;
        delete this.heads[dayId];
      }

      // Get rid of any extra rows
      let spliceCount = 0;
      for (
        let i = rowIdx + 1;
        i < this.list.length && this.list[i].type !== IRowType.HEAD;
        i++
      ) {
        spliceCount++;
      }
      if (spliceCount > 0) {
        removedRows.push(...this.list.splice(rowIdx + 1, spliceCount));
      }

      // Update size delta for removed rows and remove from day
      for (const row of removedRows) {
        // Scroll up if if above visible range
        if (rowY < scrollTop) {
          scrollTop -= row.size;
        }
        needAdjust = true;

        // Remove from day
        const idx = head.day.rows.indexOf(row);
        if (idx >= 0) head.day.rows.splice(idx, 1);
      }

      // This will be true even if the head is being spliced
      // because one row is always removed in that case
      if (needAdjust) {
        if (headRemoved) {
          // If the head was removed, we need a reflow,
          // or adjust isn't going to work right
          this.scrollerManager.reflow();
        } else {
          // Otherwise just adjust the ticks
          this.scrollerManager.adjust();
        }

        // Scroll to new position
        (<any>this.$refs.recycler).$el.scrollTop = scrollTop;
      }
    },

    /** Add and get a new blank photos row */
    addRow(day: IDay): IRow {
      let rowType = IRowType.PHOTOS;
      if (day.dayid === this.TagDayID.FOLDERS) {
        rowType = IRowType.FOLDERS;
      }

      // Create new row
      const row = {
        id: `${day.dayid}-${day.rows.length}`,
        num: day.rows.length,
        photos: [],
        type: rowType,
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
      const updatedDays = new Set<IDay>(delPhotos.map((p) => p.d));
      const delPhotosSet = new Set(delPhotos);

      // Animate the deletion
      for (const photo of delPhotos) {
        photo.flag |= this.c.FLAG_LEAVING;
      }

      // wait for 200ms
      await new Promise((resolve) => setTimeout(resolve, 200));

      // clear selection at this point
      this.selectionManager.clearSelection(delPhotos);

      // Reflow all touched days
      for (const day of updatedDays) {
        const newDetail = day.detail.filter((p) => !delPhotosSet.has(p));
        this.processDay(day.dayid, newDetail);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
@mixin phone {
  @media (max-width: 768px) {
    @content;
  }
}

/** Main view */
.container {
  height: 100%;
  width: 100%;
  overflow: hidden;
  user-select: none;

  * {
    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
  }
}

.recycler {
  will-change: scroll-position;
  contain: strict;
  height: 300px;
  width: calc(100% + 20px);
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
  }
}

.recycler .photo {
  contain: strict;
  display: block;
  position: absolute;
  top: 0;
  left: 0;
  cursor: pointer;
  height: 100%;
  transition: width 0.2s ease-in-out, height 0.2s ease-in-out,
    transform 0.2s ease-in-out; // reflow
}

.head-row {
  contain: strict;
  padding-top: 10px;
  padding-left: 3px;
  font-size: 0.9em;

  > div {
    position: relative;
    &.super {
      font-size: 1.4em;
      font-weight: bold;
      margin-bottom: 4px;
    }
    &.main {
      display: inline-block;
      font-weight: 600;
    }
  }

  .select {
    position: absolute;
    left: 0;
    top: 50%;
    display: none;
    opacity: 0;
    transform: translateY(-45%);
    transition: opacity 0.2s ease;
    border-radius: 50%;
    cursor: pointer;
  }
  .name {
    display: block;
    transition: transform 0.2s ease;
    cursor: pointer;
    font-size: 1.075em;
  }

  :hover,
  &.selected {
    .select {
      display: flex;
      opacity: 0.7;
    }
    .name {
      transform: translateX(24px);
    }
  }
  &.selected .select {
    opacity: 1;
    color: var(--color-primary);
  }

  @include phone {
    transform: translateX(8px);
  }
}

/** Static and dynamic top matter */
.top-matter {
  padding-top: 4px;
  @include phone {
    padding-left: 40px;
  }
}
.recycler-before {
  width: 100%;
  > .text {
    font-size: 1.2em;
    padding-top: 13px;
    padding-left: 8px;
    @include phone {
      padding-left: 48px;
    }
  }
}
</style>
