<template>
    <div class="container" ref="container" :class="{ 'icon-loading': loading > 0 }">
        <!-- Static top matter -->
        <div ref="topmatter" class="top-matter" v-if="topMatterType">
            <FolderTopMatter v-if="topMatterType === 1" />
            <TagTopMatter v-else-if="topMatterType === 2" />
            <FaceTopMatter v-else-if="topMatterType === 3" />
        </div>

        <!-- No content found and nothing is loading -->
        <NcEmptyContent title="Nothing to show here" v-if="loading === 0 && list.length === 0">
            <template #icon>
                <PeopleIcon v-if="$route.name === 'people'" />
                <ArchiveIcon v-else-if="$route.name === 'archive'" />
                <ImageMultipleIcon v-else />
            </template>
        </NcEmptyContent>

        <!-- Main recycler view for rows -->
        <RecycleScroller
            ref="recycler"
            class="recycler"
            :key="state"
            :items="list"
            :emit-update="true"
            :buffer="400"
            key-field="id"
            size-field="size"
            type-field="type"
            v-slot="{ item }"
            @update="scrollChange"
            @resize="handleResizeWithDelay"
        >
            <div v-if="item.type === 0" class="head-row"
                :class="{
                    'first': item.id === 1 && !topMatterType,
                    'selected': item.selected,
                }"
            >
                <CheckCircle :size="18" class="select" @click="selectHead(item)" />

                <span class="name"
                     @click="selectHead(item)">
                    {{ item.name || getHeadName(item) }}
                </span>
            </div>

            <div v-else
                class="photo-row"
                :style="{ height: rowHeight + 'px' }">

                <div class="photo" v-for="(photo, index) in item.photos" :key="index">
                    <Folder v-if="photo.flag & c.FLAG_IS_FOLDER"
                            :data="photo"
                            :rowHeight="rowHeight"
                            :key="photo.fileid" />

                    <Tag v-else-if="photo.flag & c.FLAG_IS_TAG"
                            :data="photo"
                            :rowHeight="rowHeight"
                            :key="photo.fileid" />

                    <Photo v-else
                            :data="photo"
                            :rowHeight="rowHeight"
                            :day="item.day"
                            @select="selectPhoto"
                            @reprocess="deleteFromViewWithAnimation"
                            @clickImg="clickPhoto" />
                </div>
            </div>
        </RecycleScroller>

        <!-- Timeline scroller -->
        <div ref="timelineScroll" class="timeline-scroll"
             v-bind:class="{ scrolling }"
            @mousemove="timelineHover"
            @touchmove="timelineTouch"
            @mouseleave="timelineLeave"
            @mousedown="timelineClick">
            <span class="cursor st" ref="cursorSt"
                  :style="{ transform: `translateY(${timelineCursorY}px)` }"></span>
            <span class="cursor hv"
                  :style="{ transform: `translateY(${timelineHoverCursorY}px)` }">
                  {{ timelineHoverCursorText }}
            </span>

            <div v-for="tick of visibleTimelineTicks" :key="tick.dayId"
                 class="tick"
                :class="{ 'dash': !tick.text }"
                :style="{ top: tick.topC + 'px' }">

                <span v-if="tick.text">{{ tick.text }}</span>
            </div>
        </div>

        <!-- Top bar for selections etc -->
        <div v-if="selection.size > 0" class="top-bar">
            <NcActions>
                <NcActionButton
                    :aria-label="t('memories', 'Cancel')"
                    @click="clearSelection()">
                    {{ t('memories', 'Cancel') }}
                    <template #icon> <Close :size="20" /> </template>
                </NcActionButton>
            </NcActions>

            <div class="text">
                {{ n("memories", "{n} selected", "{n} selected", selection.size, { n: selection.size }) }}
            </div>

            <NcActions :inline="1">
                <NcActionButton
                    :aria-label="t('memories', 'Delete')"
                    @click="deleteSelection">
                    {{ t('memories', 'Delete') }}
                    <template #icon> <Delete :size="20" /> </template>
                </NcActionButton>
                <NcActionButton
                    :aria-label="t('memories', 'Download')"
                    @click="downloadSelection" close-after-click>
                    {{ t('memories', 'Download') }}
                    <template #icon> <Download :size="20" /> </template>
                </NcActionButton>
                <NcActionButton
                    :aria-label="t('memories', 'Favorite')"
                    @click="favoriteSelection" close-after-click>
                    {{ t('memories', 'Favorite') }}
                    <template #icon> <Star :size="20" /> </template>
                </NcActionButton>

                <template v-if="allowArchive()">
                    <NcActionButton
                        v-if="!routeIsArchive()"
                        :aria-label="t('memories', 'Archive')"
                        @click="archiveSelection" close-after-click>
                        {{ t('memories', 'Archive') }}
                        <template #icon> <ArchiveIcon :size="20" /> </template>
                    </NcActionButton>
                    <NcActionButton
                        v-else
                        :aria-label="t('memories', 'Unarchive')"
                        @click="archiveSelection" close-after-click>
                        {{ t('memories', 'Unarchive') }}
                        <template #icon> <UnarchiveIcon :size="20" /> </template>
                    </NcActionButton>
                </template>


                <NcActionButton
                    :aria-label="t('memories', 'Edit Date/Time')"
                    @click="editDateSelection" close-after-click>
                    {{ t('memories', 'Edit Date/Time') }}
                    <template #icon> <EditIcon :size="20" /> </template>
                </NcActionButton>

                <template v-if="selection.size === 1">
                    <NcActionButton
                        :aria-label="t('memories', 'View in folder')"
                        @click="viewInFolder" close-after-click>
                        {{ t('memories', 'View in folder') }}
                        <template #icon> <OpenInNewIcon :size="20" /> </template>
                    </NcActionButton>
                </template>
            </NcActions>
        </div>

        <EditDate ref="editDate" @refresh="refresh" />
    </div>
</template>

<script lang="ts">
import { Component, Watch, Mixins } from 'vue-property-decorator';
import { IDay, IFolder, IHeadRow, IPhoto, IRow, IRowType, ITick, TopMatterType } from "../types";
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import GlobalMixin from '../mixins/GlobalMixin';
import { NcActions, NcActionButton, NcButton, NcEmptyContent } from '@nextcloud/vue';

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";
import axios from '@nextcloud/axios'
import Folder from "./Folder.vue";
import Tag from "./Tag.vue";
import Photo from "./Photo.vue";
import EditDate from "./EditDate.vue";
import FolderTopMatter from "./FolderTopMatter.vue";
import TagTopMatter from "./TagTopMatter.vue";
import FaceTopMatter from "./FaceTopMatter.vue";
import UserConfig from "../mixins/UserConfig";

import Star from 'vue-material-design-icons/Star.vue';
import Download from 'vue-material-design-icons/Download.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import Close from 'vue-material-design-icons/Close.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import EditIcon from 'vue-material-design-icons/ClockEdit.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import UnarchiveIcon from 'vue-material-design-icons/PackageUp.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue'

const SCROLL_LOAD_DELAY = 100;          // Delay in loading data when scrolling
const MAX_PHOTO_WIDTH = 175;            // Max width of a photo
const MIN_COLS = 3;                     // Min number of columns (on phone, e.g.)

// Define API routes
const API_ROUTES = {
    DAYS: 'days',
    DAY: 'days/{dayId}',
};
for (const [key, value] of Object.entries(API_ROUTES)) {
    API_ROUTES[key] = '/apps/memories/api/' + value;
}

@Component({
    components: {
        Folder,
        Tag,
        Photo,
        EditDate,
        FolderTopMatter,
        TagTopMatter,
        FaceTopMatter,
        NcActions,
        NcActionButton,
        NcButton,
        NcEmptyContent,

        Star,
        Download,
        Delete,
        Close,
        CheckCircle,
        EditIcon,
        ArchiveIcon,
        UnarchiveIcon,
        OpenInNewIcon,
        PeopleIcon,
        ImageMultipleIcon,
    }
})
export default class Timeline extends Mixins(GlobalMixin, UserConfig) {
    /** Loading days response */
    private loading = 0;
    /** Main list of rows */
    private list: IRow[] = [];
    /** Counter of rows */
    private numRows = 0;
    /** Computed number of columns */
    private numCols = 5;
    /** Header rows for dayId key */
    private heads: { [dayid: number]: IHeadRow } = {};
    /** Original days response */
    private days: IDay[] = [];

    /** Computed row height */
    private rowHeight = 100;
    /** Total height of recycler */
    private viewHeight = 1000;
    /** Total height of timeline */
    private timelineHeight = 100;
    /** Computed timeline ticks */
    private timelineTicks: ITick[] = [];
    /** Computed timeline cursor top */
    private timelineCursorY = 0;
    /** Timeline hover cursor top */
    private timelineHoverCursorY = -5;
    /** Timeline hover cursor text */
    private timelineHoverCursorText = "";

    /** Current start index */
    private currentStart = 0;
    /** Current end index */
    private currentEnd = 0;
    /** Scrolling currently */
    private scrolling = false;
    /** Scrolling timer */
    private scrollTimer = null as number | null;
    /** Resizing timer */
    private resizeTimer = null as number | null;
    /** View size reflow timer */
    private reflowTimelineReq = false;
    /** Is mobile layout */
    private isMobile = false;

    /** Set of dayIds for which images loaded */
    private loadedDays = new Set<number>();
    /** Set of selected file ids */
    private selection = new Map<number, IPhoto>();

    /** Static top matter type for current page */
    private topMatterType: TopMatterType = TopMatterType.NONE;

    /** State for request cancellations */
    private state = Math.random();

    mounted() {
        this.createState();
    }

    @Watch('$route')
    async routeChange(from: any, to: any) {
        await this.refresh();
    }

    beforeDestroy() {
        this.resetState();
    }

    created() {
        window.addEventListener("resize", this.handleResizeWithDelay);
    }

    destroyed() {
        window.removeEventListener("resize", this.handleResizeWithDelay);
    }

    /** Create new state */
    async createState() {
        // Initializations in this tick cycle
        this.setTopMatter();

        // Wait for one tick before doing anything
        await this.$nextTick();

        // Fit to window
        this.handleResize();

        // Get data
        await this.fetchDays();

        // Timeline recycler init
        (this.$refs.recycler as any).$el.addEventListener('scroll', this.scrollPositionChange, false);
        this.scrollPositionChange();
    }

    /** Reset all state */
    async resetState() {
        this.clearSelection();
        this.loading = 0;
        this.list = [];
        this.numRows = 0;
        this.heads = {};
        this.days = [];
        this.currentStart = 0;
        this.currentEnd = 0;
        this.timelineTicks = [];
        this.state = Math.random();
        this.loadedDays.clear();
    }

    /** Create top matter */
    setTopMatter() {
        switch (this.$route.name) {
            case 'folders':
                this.topMatterType = TopMatterType.FOLDER;
                break;
            case 'tags':
                this.topMatterType = this.$route.params.name ? TopMatterType.TAG : TopMatterType.NONE;
                break;
            case 'people':
                this.topMatterType = this.$route.params.name ? TopMatterType.FACE : TopMatterType.NONE;
                break;
            default:
                this.topMatterType = TopMatterType.NONE;
                break;
        }
    }

    /** Recreate everything */
    async refresh(preservePosition = false) {
        // Get current scroll position
        const origScroll = (<any>this.$refs.recycler).$el.scrollTop;

        // Reset state
        await this.resetState();
        await this.createState();

        // Restore scroll position
        if (preservePosition) {
            (<any>this.$refs.recycler).scrollToPosition(origScroll);
        }
    }

    /** Do resize after some time */
    handleResizeWithDelay() {
        if (this.resizeTimer) {
            clearTimeout(this.resizeTimer);
        }
        this.resizeTimer = window.setTimeout(() => {
            this.handleResize();
            this.resizeTimer = null;
        }, 300);
    }

    /** Handle window resize and initialization */
    handleResize() {
        const e = this.$refs.container as Element;
        const tm = this.$refs.topmatter as Element;
        let height = e.clientHeight - (tm?.clientHeight || 0);
        let width = e.clientWidth;
        this.timelineHeight = height;

        const recycler = this.$refs.recycler as any;
        recycler.$el.style.height = (height - 4) + 'px';

        // Mobile devices
        if (window.innerWidth <= 768) {
            width -= 4;
            this.isMobile = true;
        } else {
            width -= 40;
            this.isMobile = false;
        }

        if (this.days.length === 0) {
            // Don't change cols if already initialized
            this.numCols = Math.max(MIN_COLS, Math.floor(width / MAX_PHOTO_WIDTH));
        }

        this.rowHeight = Math.floor(width / this.numCols);

        // Set heights of rows
        this.list.filter(r => r.type !== IRowType.HEAD).forEach(row => {
            row.size = this.rowHeight;
        });
        this.reflowTimeline(true);
    }

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: any) {
        this.timelineCursorY = event ? event.target.scrollTop * this.timelineHeight / this.viewHeight : 0;
        this.timelineMoveHoverCursor(this.timelineCursorY);

        if (this.scrollTimer) {
            window.clearTimeout(this.scrollTimer);
        }
        this.scrolling = true;
        this.scrollTimer = window.setTimeout(() => {
            this.scrolling = false;
            this.scrollTimer = null;
        }, 1500);
    }

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
                    row.photos[j] = {
                        flag: this.c.FLAG_PLACEHOLDER,
                        fileid: Math.random(),
                    };
                }
                delete row.pct;
            }

            // Force reload all loaded images
            if ((i < this.currentStart || i > this.currentEnd) && row.photos) {
                for (const photo of row.photos) {
                    if (photo.flag & this.c.FLAG_LOADED) {
                        photo.flag = (photo.flag & ~this.c.FLAG_LOADED) | this.c.FLAG_FORCE_RELOAD;
                    }
                }
            }
        }

        // Make sure we don't do this too often
        this.currentStart = startIndex;
        this.currentEnd = endIndex;
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
    }

    /** Load image data for given view */
    loadScrollChanges(startIndex: number, endIndex: number) {
        // Make sure start and end valid
        startIndex = Math.max(0, startIndex);
        endIndex = Math.min(this.list.length - 1, endIndex);

        // Fetch all visible days
        for (let i = startIndex; i <= endIndex; i++) {
            let item = this.list[i];
            if (!item || this.loadedDays.has(item.dayId)) {
                continue;
            }

            this.loadedDays.add(item.dayId);
            this.fetchDay(item.dayId);
        }
    }

    /** Get query string for API calls */
    appendQuery(url: string) {
        const query = new URLSearchParams();

        // Favorites
        if (this.$route.name === 'favorites') {
            query.set('fav', '1');
        }

        // Videos
        if (this.$route.name === 'videos') {
            query.set('vid', '1');
        }

        // Folder
        if (this.$route.name === 'folders') {
            let path: any = this.$route.params.path || '/';
            path = typeof path === 'string' ? path : path.join('/');
            query.set('folder', path);
        }

        // Archive
        if (this.routeIsArchive()) {
            query.set('archive', '1');
        }

        // People
        if (this.$route.name === 'people' && this.$route.params.user && this.$route.params.name) {
            query.set('face', `${this.$route.params.user}/${this.$route.params.name}`);
        }

        // Tags
        if (this.$route.name === 'tags' && this.$route.params.name) {
            query.set('tag', this.$route.params.name);
        }

        // Create query string and append to URL
        const queryStr = query.toString();
        if (queryStr) {
            url += '?' + queryStr;
        }
        return url;
    }

    /** Archive is allowed only on timeline routes */
    allowArchive() {
        return this.$route.name === 'timeline'  ||
               this.$route.name === 'favorites' ||
               this.$route.name === 'videos'    ||
               this.$route.name === 'thisday'   ||
               this.$route.name === 'archive';
    }

    /** Is archive route */
    routeIsArchive() {
        return this.$route.name === 'archive';
    }

    /** Get name of header */
    getHeadName(head: IHeadRow) {
        // Check cache
        if (head.name) {
            return head.name;
        }

        // Special headers
        if (head.dayId === this.TagDayID.FOLDERS) {
            return (head.name = this.t("memories", "Folders"));
        } else if (head.dayId === this.TagDayID.TAGS) {
            return (head.name = this.t("memories", "Tags"));
        } else if (head.dayId === this.TagDayID.FACES) {
            return (head.name = this.t("memories", "People"));
        }

        // Make date string
        // The reason this function is separate from processDays is
        // because this call is terribly slow even on desktop
        const dateTaken = utils.dayIdToDate(head.dayId);
        const name = utils.getLongDateStr(dateTaken, true);

        // Cache and return
        head.name = name;
        return head.name;
    }

    /** Fetch timeline main call */
    async fetchDays() {
        let url = API_ROUTES.DAYS;
        let params: any = {};

        try {
            this.loading++;
            const startState = this.state;

            let data: IDay[] = [];
            if (this.$route.name === 'thisday') {
                data = await dav.getOnThisDayData();
            } else if (this.$route.name === 'tags' && !this.$route.params.name) {
                data = await dav.getTagsData();
            } else if (this.$route.name === 'people' && !this.$route.params.name) {
                data = await dav.getPeopleData();
            } else {
                data = (await axios.get<IDay[]>(generateUrl(this.appendQuery(url), params))).data;
            }

            if (this.state !== startState) return;
            await this.processDays(data);
        } catch (err) {
            console.error(err);
            showError(err?.response?.data?.message || err.message);
        } finally {
            this.loading--;
        }
    }

    /** Process the data for days call including folders */
    async processDays(data: IDay[]) {
        const list: typeof this.list = [];
        const heads: typeof this.heads = {};

        // Store the preloads in a separate map.
        // This is required since otherwise the inner detail objects
        // do not become reactive (which happens only after assignment).
        const preloads: {
            [dayId: number]: {
                day: IDay,
                detail: IPhoto[],
            };
        } = {};

        for (const day of data) {
            // Initialization
            day.rows = new Set();

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

            // Add header to list
            const head: IHeadRow = {
                id: ++this.numRows,
                size: 40,
                type: IRowType.HEAD,
                selected: false,
                dayId: day.dayid,
                day: day,
            };
            heads[day.dayid] = head;
            list.push(head);

            // Add rows
            const nrows = Math.ceil(day.count / this.numCols);
            for (let i = 0; i < nrows; i++) {
                const row = this.getBlankRow(day);
                list.push(row);
                day.rows.add(row);

                // Add placeholder count
                const leftNum = (day.count - i * this.numCols);
                row.pct = leftNum > this.numCols ? this.numCols : leftNum;
                row.photos = [];
            }
        }

        // Store globally
        this.days = data;
        this.list = list;
        this.heads = heads;

        // Iterate the preload map
        // Now the inner detail objects are reactive
        for (const dayId in preloads) {
            const preload = preloads[dayId];
            preload.day.detail = preload.detail;
            this.processDay(preload.day);
        }

        // Fix view height variable
        await this.reflowTimeline();
    }

    /** Fetch image data for one dayId */
    async fetchDay(dayId: number) {
        let url = API_ROUTES.DAY;
        const params: any = { dayId };

        // Do this in advance to prevent duplicate requests
        this.loadedDays.add(dayId);

        try {
            const startState = this.state;
            const res = await axios.get<IPhoto[]>(generateUrl(this.appendQuery(url), params));
            const data = res.data;
            if (this.state !== startState) return;

            const day = this.days.find(d => d.dayid === dayId);
            day.detail = data;
            day.count = data.length;
            this.processDay(day);
        } catch (e) {
            showError(this.t('memories', 'Failed to load some photos'));
            console.error(e);
        }
    }

    /** Re-create timeline tick data in the next frame */
    async reflowTimeline(orderOnly = false) {
        if (this.reflowTimelineReq) {
            return;
        }

        this.reflowTimelineReq = true;
        await this.$nextTick();
        this.reflowTimelineNow(orderOnly);
        this.reflowTimelineReq = false;
    }

    /** Re-create timeline tick data */
    reflowTimelineNow(orderOnly = false) {
        if (!orderOnly) {
            this.recreateTimeline();
        }

        const recycler: any = this.$refs.recycler;
        this.viewHeight = recycler.$refs.wrapper.clientHeight;

        // Compute timeline tick positions
        for (const tick of this.timelineTicks) {
            tick.topC = (tick.topS + tick.top * this.rowHeight) * this.timelineHeight / this.viewHeight;
        }

        // Do another pass to figure out which timeline points are visible
        // This is not as bad as it looks, it's actually 12*O(n)
        // because there are only 12 months in a year
        const fontSizePx = parseFloat(getComputedStyle(this.$refs.cursorSt as any).fontSize);
        const minGap = fontSizePx + (this.isMobile ? 5 : 2);
        let prevShow = -9999;
        for (const [idx, tick] of this.timelineTicks.entries()) {
            // You can't see these anyway, why bother?
            if (tick.topC < minGap || tick.topC > this.timelineHeight - minGap) {
                tick.s = false;
                continue;
            }

            // Will overlap with the previous tick. Skip anyway.
            if (tick.topC - prevShow < minGap) {
                tick.s = false;
                continue;
            }

            // This is a labelled tick then show it anyway for the sake of best effort
            if (tick.text) {
                tick.s = true;
                prevShow = tick.topC;
                continue;
            }

            // Lookahead for next labelled tick
            // If showing this tick would overlap the next one, don't show this one
            let i = idx + 1;
            while(i < this.timelineTicks.length) {
                if (this.timelineTicks[i].text) {
                    break;
                }
                i++;
            }
            if (i < this.timelineTicks.length) {
                // A labelled tick was found
                const nextLabelledTick = this.timelineTicks[i];
                if (tick.topC + minGap > nextLabelledTick.topC &&
                    nextLabelledTick.topC < this.timelineHeight - minGap) { // make sure this will be shown
                    tick.s = false;
                    continue;
                }
            }

            // Show this tick
            tick.s = true;
            prevShow = tick.topC;
        }
    }

    /**
     * Recreate the timeline from scratch
     */
    recreateTimeline() {
        // Clear timeline
        this.timelineTicks = [];

        // Ticks
        let currTopRow = 0;
        let currTopStatic = 0;
        let prevYear = 9999;
        let prevMonth = 0;
        const thisYear = new Date().getFullYear();

        // Get a new tick
        const getTick = (day: IDay, text?: string | number): ITick => {
            return {
                dayId: day.dayid,
                top: currTopRow,
                topS: currTopStatic,
                topC: 0,
                text: text,
            };
        }

        // Itearte over days
        for (const day of this.days) {
            if (day.count === 0) {
                continue;
            }

            if (Object.values(this.TagDayID).includes(day.dayid)) {
                // Blank dash ticks only
                this.timelineTicks.push(getTick(day));
            } else {
                // Make date string
                const dateTaken = utils.dayIdToDate(day.dayid);

                // Create tick if month changed
                const dtYear = dateTaken.getUTCFullYear();
                const dtMonth = dateTaken.getUTCMonth()
                if (Number.isInteger(day.dayid) && (dtMonth !== prevMonth || dtYear !== prevYear)) {
                    this.timelineTicks.push(getTick(day, (dtYear === prevYear || dtYear === thisYear) ? undefined : dtYear));
                }
                prevMonth = dtMonth;
                prevYear = dtYear;
            }

            currTopStatic += this.heads[day.dayid].size;
            currTopRow += day.rows.size;
        }
    }

    /**
     * Process items from day response.
     *
     * @param day Day object
     */
    processDay(day: IDay) {
        const dayId = day.dayid;
        const data = day.detail;

        const head = this.heads[dayId];
        this.loadedDays.add(dayId);

        // Reset rows including placeholders
        if (head.day?.rows) {
            for (const row of head.day.rows) {
                row.photos = [];
            }
        }
        head.day.rows.clear();

        // Check if some row was added
        let addedRow = false;

        // Get index of header O(n)
        const headIdx = this.list.findIndex(item => item.id === head.id);
        let rowIdx = headIdx + 1;

        // Add all rows
        let dataIdx = 0;
        while (dataIdx < data.length) {
            // Check if we ran out of rows
            if (rowIdx >= this.list.length || this.list[rowIdx].type === IRowType.HEAD) {
                addedRow = true;
                this.list.splice(rowIdx, 0, this.getBlankRow(day));
            }

            const row = this.list[rowIdx];

            // Go to the next row
            if (row.photos.length >= this.numCols) {
                rowIdx++;
                continue;
            }

            // Add the photo to the row
            const photo = data[dataIdx];
            if (typeof photo.flag === "undefined") {
                photo.flag = 0; // flags
                photo.d = day; // backref to day
            }

            // Flag conversion
            if (photo.isvideo) {
                photo.flag |= this.c.FLAG_IS_VIDEO;
                delete photo.isvideo;
            }
            if (photo.isfavorite) {
                photo.flag |= this.c.FLAG_IS_FAVORITE;
                delete photo.isfavorite;
            }
            if (photo.isfolder) {
                photo.flag |= this.c.FLAG_IS_FOLDER;
                delete photo.isfolder;
            }
            if (photo.isface) {
                photo.flag |= this.c.FLAG_IS_FACE;
                delete photo.isface;
            }
            if (photo.istag) {
                photo.flag |= this.c.FLAG_IS_TAG;
                delete photo.istag;
            }

            // Move to next index of photo
            dataIdx++;

            // Hidden folders
            if (!this.config_showHidden &&
                (photo.flag & this.c.FLAG_IS_FOLDER) &&
                (<IFolder>photo).name.startsWith('.'))
            {
                continue;
            }

            this.list[rowIdx].photos.push(photo);

            // Add row to day
            head.day.rows.add(row);
        }

        // No rows, splice everything including the header
        if (head.day.rows.size === 0) {
            this.list.splice(headIdx, 1);
            rowIdx = headIdx - 1;
            delete this.heads[dayId];
        }

        // Get rid of any extra rows
        let spliceCount = 0;
        for (let i = rowIdx + 1; i < this.list.length && this.list[i].type !== IRowType.HEAD; i++) {
            spliceCount++;
        }
        if (spliceCount > 0) {
            this.list.splice(rowIdx + 1, spliceCount);
        }

        // This will be true even if the head is being spliced
        // because one row is always removed in that case
        // So just reflow the timeline here
        if (addedRow || spliceCount > 0) {
            this.reflowTimeline();
        }
    }

    /** Get a new blank photos row */
    getBlankRow(day: IDay): IRow {
        let rowType = IRowType.PHOTOS;
        if (day.dayid === this.TagDayID.FOLDERS) {
            rowType = IRowType.FOLDERS;
        }

        return {
            id: ++this.numRows,
            photos: [],
            type: rowType,
            size: this.rowHeight,
            dayId: day.dayid,
            day: day,
        };
    }

    /** Get the visible timeline ticks */
    get visibleTimelineTicks() {
        return this.timelineTicks.filter(tick => tick.s);
    }

    timelineMoveHoverCursor(y: number) {
        this.timelineHoverCursorY = y;

        // Get index of previous tick
        let idx = this.timelineTicks.findIndex(t => t.topC > y);
        if (idx >= 1) {
            idx = idx - 1;
        } else if (idx === -1 && this.timelineTicks.length > 0) {
            idx = this.timelineTicks.length - 1;
        } else {
            return;
        }

        // DayId of current hover
        const dayId = this.timelineTicks[idx].dayId

        // Special days
        if (Object.values(this.TagDayID).includes(dayId)) {
            this.timelineHoverCursorText = this.getHeadName(this.heads[dayId]);
            return;
        }

        const date = utils.dayIdToDate(dayId);
        this.timelineHoverCursorText = utils.getShortDateStr(date);
    }

    /** Handle mouse hover on right timeline */
    timelineHover(event: MouseEvent) {
        if (event.buttons) {
            this.timelineClick(event);
        }
        this.timelineMoveHoverCursor(event.offsetY);
    }

    /** Handle mouse leave on right timeline */
    timelineLeave() {
        this.timelineMoveHoverCursor(this.timelineCursorY);
    }

    /** Handle mouse click on right timeline */
    timelineClick(event: MouseEvent) {
        const recycler: any = this.$refs.recycler;
        recycler.scrollToPosition(this.getTimelinePosition(event.offsetY));
    }

    /** Handle touch on right timeline */
    timelineTouch(event: any) {
        const rect = event.target.getBoundingClientRect();
        const y = event.targetTouches[0].pageY - rect.top;
        const recycler: any = this.$refs.recycler;
        recycler.scrollToPosition(this.getTimelinePosition(y));
        event.preventDefault();
        event.stopPropagation();
    }

    /** Get recycler equivalent position from event */
    getTimelinePosition(y: number) {
        const tH = this.viewHeight;
        const maxH = this.timelineHeight;
        return y * tH / maxH;
    }

    /** Clicking on photo */
    clickPhoto(photoComponent: any) {
        if (this.selection.size > 0) { // selection mode
            photoComponent.toggleSelect();
        } else {
            photoComponent.openFile();
        }
    }

    /** Add a photo to selection list */
    selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
        if (photo.flag & this.c.FLAG_PLACEHOLDER ||
            photo.flag & this.c.FLAG_IS_FOLDER ||
            photo.flag & this.c.FLAG_IS_TAG
        ) {
            return; // ignore placeholders
        }

        const nval = val ?? !this.selection.has(photo.fileid);
        if (nval) {
            photo.flag |= this.c.FLAG_SELECTED;
            this.selection.set(photo.fileid, photo);
        } else {
            photo.flag &= ~this.c.FLAG_SELECTED;
            this.selection.delete(photo.fileid);
        }

        if (!noUpdate) {
            this.updateHeadSelected(this.heads[photo.d.dayid]);
            this.$forceUpdate();
        }
    }

    /** Clear all selected photos */
    clearSelection(only?: IPhoto[]) {
        const heads = new Set<IHeadRow>();
        const toClear = only || this.selection.values();
        Array.from(toClear).forEach((photo: IPhoto) => {
            photo.flag &= ~this.c.FLAG_SELECTED;
            heads.add(this.heads[photo.d.dayid]);
            this.selection.delete(photo.fileid);
        });
        heads.forEach(this.updateHeadSelected);
        this.$forceUpdate();
    }

    /** Select or deselect all photos in a head */
    selectHead(head: IHeadRow) {
        head.selected = !head.selected;
        for (const row of head.day.rows) {
            for (const photo of row.photos) {
                this.selectPhoto(photo, head.selected, true);
            }
        }
        this.$forceUpdate();
    }

    /** Check if the day for a photo is selected entirely */
    updateHeadSelected(head: IHeadRow) {
        let selected = true;

        // Check if all photos are selected
        for (const row of head.day.rows) {
            for (const photo of row.photos) {
                if (!(photo.flag & this.c.FLAG_SELECTED)) {
                    selected = false;
                    break;
                }
            }
        }

        // Update head
        head.selected = selected;
    }

    /**
     * Download the currently selected files
     */
    async downloadSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to download a large number of files. Are you sure?"))) {
                return;
            }
        }
        await dav.downloadFilesByIds(Array.from(this.selection.keys()));
    }

    /**
     * Check if all files selected currently are favorites
     */
    allSelectedFavorites() {
        return Array.from(this.selection.values()).every(p => p.flag & this.c.FLAG_IS_FAVORITE);
    }

    /**
     * Favorite the currently selected photos
     */
    async favoriteSelection() {
        try {
            const val = !this.allSelectedFavorites();
            this.loading++;
            for await (const favIds of dav.favoriteFilesByIds(Array.from(this.selection.keys()), val)) {
                favIds.forEach(id => {
                    const photo = this.selection.get(id);
                    if (!photo) {
                        return;
                    }

                    if (val) {
                        photo.flag |= this.c.FLAG_IS_FAVORITE;
                    } else {
                        photo.flag &= ~this.c.FLAG_IS_FAVORITE;
                    }
                });
            }
            this.clearSelection();
        } finally {
            this.loading--;
        }
    }

    /**
     * Delete the currently selected photos
     */
    async deleteSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to delete a large number of files. Are you sure?"))) {
                return;
            }
        }

        try {
            this.loading++;
            for await (const delIds of dav.deleteFilesByIds(Array.from(this.selection.keys()))) {
                const delPhotos = delIds.map(id => this.selection.get(id));
                await this.deleteFromViewWithAnimation(delPhotos);
            }
        } catch (error) {
            console.error(error);
        } finally {
            this.loading--;
        }
    }

    /**
     * Open the edit date dialog
     */
    async editDateSelection() {
        (<any>this.$refs.editDate).open(Array.from(this.selection.values()));
    }

    /**
     * Open the files app with the selected file (one)
     * Opens a new window.
     */
    async viewInFolder() {
        if (this.selection.size !== 1) return;

        const photo: IPhoto = this.selection.values().next().value;
        const f = await dav.getFiles([photo.fileid]);
        if (f.length === 0) return;

        const file = f[0];
        const dirPath = file.filename.split('/').slice(0, -1).join('/')
        const url = generateUrl(`/apps/files/?dir=${dirPath}&scrollto=${file.fileid}&openfile=${file.fileid}`);
        window.open(url, '_blank');
    }

    /**
     * Archive the currently selected photos
     */
    async archiveSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to touch a large number of files. Are you sure?"))) {
                return;
            }
        }

        try {
            this.loading++;
            for await (let delIds of dav.archiveFilesByIds(Array.from(this.selection.keys()), !this.routeIsArchive())) {
                delIds = delIds.filter(x => x);
                if (delIds.length === 0) {
                    continue
                }
                const delPhotos = delIds.map(id => this.selection.get(id));
                await this.deleteFromViewWithAnimation(delPhotos);
            }
        } catch (error) {
            console.error(error);
        } finally {
            this.loading--;
        }
    }

    /**
     * Delete elements from main view with some animation
     * This function looks horribly slow, probably isn't that bad
     * in all practical scenarios.
     *
     * This is also going to update day.detail for you and make
     * a call to processDay so just pass it the list of ids to
     * delete and the days that were updated.
     *
     * @param delPhotos photos to delete
     */
    async deleteFromViewWithAnimation(delPhotos: IPhoto[]) {
        if (delPhotos.length === 0) {
            return;
        }

        // Get all days that need to be updatd
        const updatedDays = new Set<IDay>(delPhotos.map(p => p.d));
        const delPhotosSet = new Set(delPhotos);

        // Animate the deletion
        for (const photo of delPhotos) {
            photo.flag |= this.c.FLAG_LEAVING;
        }

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // clear selection at this point
        this.clearSelection(delPhotos);

        // Speculate day reflow for animation
        const exitedLeft = new Set<IPhoto>();
        for (const day of updatedDays) {
            let nextExit = false;
            for (const row of day.rows) {
                for (const photo of row.photos) {
                    if (photo.flag & this.c.FLAG_LEAVING) {
                        nextExit = true;
                    } else if (nextExit) {
                        photo.flag |= this.c.FLAG_EXIT_LEFT;
                        exitedLeft.add(photo);
                    }
                }
            }
        }

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // Reflow all touched days
        for (const day of updatedDays) {
            day.detail = day.detail.filter(p => !delPhotosSet.has(p));
            day.count = day.detail.length;
            this.processDay(day);
        }

        // Enter from right all photos that exited left
        exitedLeft.forEach((photo: any) => {
            photo.flag &= ~this.c.FLAG_EXIT_LEFT;
            photo.flag |= this.c.FLAG_ENTER_RIGHT;
        });

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // Clear enter right flags
        exitedLeft.forEach((photo: any) => {
            photo.flag &= ~this.c.FLAG_ENTER_RIGHT;
        });

        // Reflow timeline
        this.reflowTimeline();
    }
}
</script>

<style lang="scss" scoped>

@mixin phone {
  @media (max-width: 768px) { @content; }
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
    height: 300px;
    width: calc(100% + 20px);
}

.photo-row > .photo {
    display: inline-block;
    position: relative;
    cursor: pointer;
    vertical-align: top;
}

.head-row {
    height: 40px;
    padding-top: 10px;
    padding-left: 3px;
    font-size: 0.9em;
    font-weight: 600;

    @include phone {
        &.first {
            padding-left: 38px;
            padding-top: 12px;
        }
    }

    > .select {
        position: absolute;
        left: 5px; top: 50%;
        display: none;
        opacity: 0;
        transform: translateY(-30%);
        transition: opacity 0.2s ease;
        border-radius: 50%;
        cursor: pointer;
    }
    > .name {
        transition: margin 0.2s ease;
        cursor: pointer;
    }

    .hover &, &.selected {
        > .select {
            display: flex;
            opacity: 0.7;
        }
        > .name {
            margin-left: 25px;
        }
    }
    &.selected > .select {
        opacity: 1;
    }
}

/** Timeline */
.timeline-scroll {
    overflow-y: clip;
    position: absolute;
    height: 100%;
    width: 36px;
    top: 0; right: 0;
    cursor: ns-resize;
    opacity: 0;
    transition: opacity .2s ease-in-out;

    // Show ticks on hover or scroll of main window
    &:hover, &.scrolling {
        opacity: 1;
    }

    // Hide ticks on mobile unless hovering
    @include phone {
        &:not(:hover) > .tick {
            opacity: 0;
        }
    }

    > .tick {
        pointer-events: none;
        position: absolute;
        font-size: 0.75em;
        line-height: 0.75em;
        font-weight: 600;
        opacity: 0.95;
        right: 9px;
        transform: translateY(-50%);
        z-index: 1;

        &.dash {
            height: 4px;
            width: 4px;
            border-radius: 50%;
            background-color: var(--color-main-text);
            opacity: 0.15;
            display: block;
            @include phone { display: none; }
        }

        @include phone {
            background-color: var(--color-main-background);
            padding: 4px;
            border-radius: 4px;
        }
    }

    > .cursor {
        position: absolute;
        pointer-events: none;
        right: 0;
        background-color: var(--color-primary);
        min-width: 100%;
        min-height: 1.5px;

        &.st {
            font-size: 0.75em;
            opacity: 0;
        }

        &.hv {
            background-color: var(--color-main-background);
            padding: 2px 5px;
            border-top: 2px solid var(--color-primary);
            border-radius: 2px;
            width: auto;
            white-space: nowrap;
            z-index: 100;
            font-size: 0.95em;
            font-weight: 600;
        }
    }
    &:hover > .cursor.st {
        opacity: 1;
    }
}

/** Top bar for selected items */
.top-bar {
    position: absolute;
    top: 10px; right: 60px;
    padding: 8px;
    width: 400px;
    max-width: calc(100vw - 30px);
    background-color: var(--color-main-background);
    box-shadow: 0 0 2px gray;
    border-radius: 10px;
    opacity: 0.95;
    display: flex;
    vertical-align: middle;

    > .text {
        flex-grow: 1;
        line-height: 40px;
        padding-left: 8px;
    }

    @include phone {
        top: 35px; right: 15px;
    }
}

/** Static top matter */
.top-matter {
    padding-top: 4px;
    @include phone {
        padding-left: 38px;
    }
}
</style>