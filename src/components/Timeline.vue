<template>
    <div class="container" ref="container" :class="{ 'icon-loading': loading }">
        <!-- Main recycler view for rows -->
        <RecycleScroller
            ref="recycler"
            class="recycler"
            :items="list"
            size-field="size"
            key-field="id"
            v-slot="{ item }"
            :emit-update="true"
            @update="scrollChange"
            @resize="handleResizeWithDelay"
        >
            <h1 v-if="item.head" class="head-row" :class="{ 'first': item.id === 1 }">
                {{ getHeadName(item) }}
            </h1>

            <div v-else
                class="photo-row"
                :style="{ height: rowHeight + 'px' }">

                <div class="photo" v-for="photo of item.photos" :key="photo.fileid">
                    <Folder v-if="photo.is_folder"
                            :data="photo" :rowHeight="rowHeight" />
                    <Photo v-else
                            :data="photo" :rowHeight="rowHeight" :day="item.day"
                            @select="selectPhoto"
                            @reprocess="deleteFromViewWithAnimation"
                            @clickImg="clickPhoto" />
                </div>
            </div>
        </RecycleScroller>

        <!-- Timeline -->
        <div ref="timelineScroll" class="timeline-scroll"
             v-bind:class="{ scrolling }"
            @mousemove="timelineHover"
            @touchmove="timelineTouch"
            @mouseleave="timelineLeave"
            @mousedown="timelineClick">
            <span class="cursor st" ref="cursorSt"
                  :style="{ top: timelineCursorY + 'px' }"></span>
            <span class="cursor hv"
                  :style="{ transform: `translateY(${timelineHoverCursorY}px)` }">
                  {{ timelineHoverCursorText }}
            </span>

            <div v-for="tick of timelineTicks" :key="tick.dayId"
                 v-if="tick.s"
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
                    :aria-label="t('memories', 'Cancel selection')"
                    @click="clearSelection"
                    class="icon-close">
                    {{ t('memories', 'Cancel') }}
                </NcActionButton>
            </NcActions>

            <div class="text">
                {{ selection.size }} item(s) selected
            </div>

            <NcActions>
                <NcActionButton
                    :aria-label="t('memories', 'Download selection')"
                    @click="downloadSelection"
                    class="icon-download">
                    {{ t('memories', 'Download') }}
                </NcActionButton>
            </NcActions>
            <NcActions>
                <NcActionButton
                    :aria-label="t('memories', 'Delete selection')"
                    @click="deleteSelection"
                    class="icon-delete">
                    {{ t('memories', 'Delete') }}
                </NcActionButton>
            </NcActions>
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Watch, Vue } from 'vue-property-decorator';
import { IDay, IPhoto, IRow, ITick } from "../types";
import { NcActions, NcActionButton, NcButton } from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router'

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";
import axios from '@nextcloud/axios'
import Folder from "./Folder.vue";
import Photo from "./Photo.vue";
import constants from "../mixins/constants";

const SCROLL_LOAD_DELAY = 100;          // Delay in loading data when scrolling
const MAX_PHOTO_WIDTH = 175;            // Max width of a photo
const MIN_COLS = 3;                     // Min number of columns (on phone, e.g.)

// Define API routes
const API_ROUTES = {
    DAYS: 'days',
    DAY: 'days/{dayId}',

    FOLDER_DAYS: 'folder/{folderId}',
    FOLDER_DAY: 'folder/{folderId}/{dayId}',
};
for (const [key, value] of Object.entries(API_ROUTES)) {
    API_ROUTES[key] = '/apps/memories/api/' + value;
}

@Component({
    components: {
        Folder,
        Photo,
        NcActions,
        NcActionButton,
        NcButton
    }
})
export default class Timeline extends Vue {
    /** Loading days response */
    private loading = true;
    /** Main list of rows */
    private list: IRow[] = [];
    /** Counter of rows */
    private numRows = 0;
    /** Computed number of columns */
    private numCols = 5;
    /** Header rows for dayId key */
    private heads: { [dayid: number]: IRow } = {};
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
    private reflowTimelineTimer = null as number | null;
    /** Is mobile layout */
    private isMobile = false;

    /** Set of dayIds for which images loaded */
    private loadedDays = new Set<number>();
    /** Set of selected file ids */
    private selection = new Set<IPhoto>();

    /** State for request cancellations */
    private state = Math.random();

    /** Constants for HTML template */
    private readonly c = constants;

    mounted() {
        this.handleResize();
        this.fetchDays();

        // Timeline recycler init
        (this.$refs.recycler as any).$el.addEventListener('scroll', this.scrollPositionChange, false);
        this.scrollPositionChange();
    }

    @Watch('$route')
    routeChange(from: any, to: any) {
        this.resetState();
        this.fetchDays();
    };

    beforeDestroy() {
        this.resetState();
    }

    /** Reset all state */
    resetState() {
        this.clearSelection();
        this.loading = true;
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
        let height = e.clientHeight;
        let width = e.clientWidth;
        this.timelineHeight = e.clientHeight;

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
        this.list.filter(r => !r.head).forEach(row => {
            row.size = this.rowHeight;
        });
        this.reflowTimeline();
    }

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: any) {
        if (event) {
            this.timelineCursorY = event.target.scrollTop * this.timelineHeight / this.viewHeight;
            this.timelineMoveHoverCursor(this.timelineCursorY);
        }

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
                        flag: constants.FLAG_PLACEHOLDER,
                        fileid: row.dayId * 10000 + i * 1000 + j,
                    };
                }
                delete row.pct;
            }

            // Force reload all loaded images
            if ((i < this.currentStart || i > this.currentEnd) && row.photos) {
                for (const photo of row.photos) {
                    if (photo.flag & constants.FLAG_LOADED) {
                        photo.flag = (photo.flag & ~constants.FLAG_LOADED) | constants.FLAG_FORCE_RELOAD;
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

        // Create query string and append to URL
        const queryStr = query.toString();
        if (queryStr) {
            url += '?' + queryStr;
        }
        return url;
    }

    /** Get name of header */
    getHeadName(head: IRow) {
        // Check cache
        if (head.name) {
            return head.name;
        }

        // Special headers
        if (head.dayId === -0.1) {
            head.name = "Folders";
            return head.name;
        }

        // Make date string
        // The reason this function is separate from processDays is
        // because this call is terribly slow even on desktop
        const dateTaken = utils.dayIdToDate(head.dayId);
        let name = dateTaken.toLocaleDateString("en-US", { dateStyle: 'full', timeZone: 'UTC' });
        if (dateTaken.getUTCFullYear() === new Date().getUTCFullYear()) {
            // hack: remove last 6 characters of date string
            name = name.substring(0, name.length - 6);
        }

        // Cache and return
        head.name = name;
        return head.name;
    }

    /** Fetch timeline main call */
    async fetchDays() {
        let url = API_ROUTES.DAYS;
        let params: any = {};

        if (this.$route.name === 'folders') {
            url = API_ROUTES.FOLDER_DAYS;
            params.folderId = this.$route.params.id || 0;
        }

        const startState = this.state;
        const res = await axios.get<IDay[]>(generateUrl(this.appendQuery(url), params));
        const data = res.data;
        if (this.state !== startState) return;
        this.processDays(data);
    }

    /** Process the data for days call including folders */
    processDays(data: IDay[]) {
        const list: IRow[] = [];
        const heads: {[dayId: number]: IRow} = {};

        for (const day of data) {
            day.count = Number(day.count);
            day.rows = new Set();

            // Nothing here
            if (day.count === 0) {
                continue;
            }

            // Add header to list
            const head = {
                id: ++this.numRows,
                size: 40,
                head: true,
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

        // Check preloads
        for (const day of data) {
            if (day.count && day.detail) {
                this.processDay(day);
            }
        }

        // Fix view height variable
        this.reflowTimeline();
        this.loading = false;
    }

    /** Fetch image data for one dayId */
    async fetchDay(dayId: number) {
        let url = API_ROUTES.DAY;
        const params: any = { dayId };

        if (this.$route.name === 'folders') {
            url = API_ROUTES.FOLDER_DAY;
            params.folderId = this.$route.params.id || 0;
        }

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
            console.error(e);
        }
    }

    /** Re-create timeline tick data in the next frame */
    reflowTimeline() {
        if (this.reflowTimelineTimer) {
            return;
        }

        this.reflowTimelineTimer = window.setTimeout(() => {
            this.reflowTimelineTimer = null;
            this.reflowTimelineNow();
        }, 0);
    }

    /** Re-create timeline tick data */
    reflowTimelineNow() {
        // Clear timeline
        this.timelineTicks = [];

        // Ticks
        let currTopRow = 0;
        let currTopStatic = 0;
        let prevYear = 9999;
        let prevMonth = 0;
        const thisYear = new Date().getFullYear();

        // Itearte over days
        for (const day of this.days) {
            if (day.count === 0) {
                continue;
            }

            // Make date string
            const dateTaken = utils.dayIdToDate(day.dayid);

            // Create tick if month changed
            const dtYear = dateTaken.getUTCFullYear();
            const dtMonth = dateTaken.getUTCMonth()
            if (Number.isInteger(day.dayid) && (dtMonth !== prevMonth || dtYear !== prevYear)) {
                // Create tick
                this.timelineTicks.push({
                    dayId: day.dayid,
                    top: currTopRow,
                    topS: currTopStatic,
                    topC: 0,
                    text: (dtYear === prevYear || dtYear === thisYear) ? undefined : dtYear,
                });
            }
            prevMonth = dtMonth;
            prevYear = dtYear;

            currTopStatic += this.heads[day.dayid].size;
            currTopRow += day.rows.size;
        }

        const recycler: any = this.$refs.recycler;
        this.viewHeight = recycler.$refs.wrapper.clientHeight;

        // Compute timeline tick positions
        for (const tick of this.timelineTicks) {
            tick.topC = Math.floor((tick.topS + tick.top * this.rowHeight) * this.timelineHeight / this.viewHeight);
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
     * Process items from day response.
     * Do not auto reflow if you plan to cal the reflow function later.
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
        head.day.rows = new Set();

        // Check if some row was added
        let addedRow = false;

        // Get index of header O(n)
        const headIdx = this.list.findIndex(item => item.id === head.id);
        let rowIdx = headIdx + 1;

        // Add all rows
        let dataIdx = 0;
        while (dataIdx < data.length) {
            // Check if we ran out of rows
            if (rowIdx >= this.list.length || this.list[rowIdx].head) {
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
                photo.flag |= constants.FLAG_IS_VIDEO;
                delete photo.isvideo;
            }
            if (photo.isfavorite) {
                photo.flag |= constants.FLAG_IS_FAVORITE;
                delete photo.isfavorite;
            }

            this.list[rowIdx].photos.push(photo);
            dataIdx++;

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
        for (let i = rowIdx + 1; i < this.list.length && !this.list[i].head; i++) {
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

    /** Get a new blank row */
    getBlankRow(day: IDay): IRow {
        return {
            id: ++this.numRows,
            photos: [],
            size: this.rowHeight,
            dayId: day.dayid,
            day: day,
        };
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

        const date = utils.dayIdToDate(this.timelineTicks[idx].dayId);
        this.timelineHoverCursorText = `${utils.getMonthName(date)} ${date.getUTCFullYear()}`;
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
    selectPhoto(photo: IPhoto) {
        const nval = !this.selection.has(photo);
        if (nval) {
            photo.flag |= constants.FLAG_SELECTED;
            this.selection.add(photo);
        } else {
            photo.flag &= ~constants.FLAG_SELECTED;
            this.selection.delete(photo);
        }
        this.$forceUpdate();
    }

    /** Clear all selected photos */
    clearSelection() {
        for (const photo of this.selection) {
            photo.flag &= ~constants.FLAG_SELECTED;
        }
        this.selection.clear();
        this.$forceUpdate();
    }

    /**
     * Download the currently selected files
     */
    async downloadSelection() {
        await dav.downloadFilesByIds([...this.selection].map(p => p.fileid));
    }

    /**
     * Delete the currently selected photos
     */
    async deleteSelection() {
        this.loading = true;
        const list = [...this.selection];
        const delIds = await dav.deleteFilesByIds(list.map(p => p.fileid));
        this.loading = false;

        const updatedDays = new Set(list.filter(f => delIds.has(f.fileid)).map(f => f.d));
        await this.deleteFromViewWithAnimation(delIds, updatedDays);
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
     * @param {Set} delIds Set of file ids to delete
     * @param {Set} updatedDays of days that MAY be affected
     */
    async deleteFromViewWithAnimation(delIds, updatedDays) {
        if (delIds.size === 0 || updatedDays.size === 0) {
            return;
        }

        // Animate the deletion
        for (const day of updatedDays) {
            for (const row of day.rows) {
                for (const photo of row.photos) {
                    if (delIds.has(photo.fileid)) {
                        photo.flag |= constants.FLAG_LEAVING;
                    }
                }
            }
        }

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // Speculate day reflow for animation
        const exitedLeft = new Set();
        for (const day of updatedDays) {
            let nextExit = false;
            for (const row of day.rows) {
                for (const photo of row.photos) {
                    if (photo.flag & constants.FLAG_LEAVING) {
                        nextExit = true;
                    } else if (nextExit) {
                        photo.flag |= constants.FLAG_EXIT_LEFT;
                        exitedLeft.add(photo);
                    }
                }
            }
        }

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // Reflow all touched days
        for (const day of updatedDays) {
            day.detail = day.detail.filter(p => !delIds.has(p.fileid));
            day.count = day.detail.length;
            this.processDay(day);
        }

        // Enter from right all photos that exited left
        exitedLeft.forEach((photo: any) => {
            photo.flag &= ~constants.FLAG_EXIT_LEFT;
            photo.flag |= constants.FLAG_ENTER_RIGHT;
        });

        // clear selection at this point
        this.clearSelection();

        // wait for 200ms
        await new Promise(resolve => setTimeout(resolve, 200));

        // Clear enter right flags
        exitedLeft.forEach((photo: any) => {
            photo.flag &= ~constants.FLAG_ENTER_RIGHT;
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
}

.recycler {
    height: 300px;
    width: calc(100% + 20px);

    .photo-row .photo {
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

    &:hover, &.scrolling {
        opacity: 1;
    }

    .tick {
        pointer-events: none;
        position: absolute;
        font-size: 0.75em;
        font-weight: 600;
        opacity: 0.95;
        right: 7px;
        transform: translateY(-50%);
        z-index: 1;

        &.dash {
            height: 4px;
            width: 4px;
            border-radius: 50%;
            background-color: var(--color-main-text);
            opacity: 0.2;
            display: block;
            @include phone { display: none; }
        }

        @include phone {
            background-color: var(--color-main-background);
            padding: 0px 4px;
            border-radius: 4px;
        }
    }

    .cursor {
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
    &:hover .cursor.st {
        opacity: 1;
    }
}

/** Top bar */
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

    .text {
        flex-grow: 1;
        line-height: 40px;
        padding-left: 8px;
    }

    @include phone {
        top: 35px; right: 15px;
    }
}
</style>