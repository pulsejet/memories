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
                {{ item.name }}
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

<script>
import * as dav from "../services/DavRequests";
import axios from '@nextcloud/axios'
import Folder from "./Folder";
import Photo from "./Photo";
import constants from "../mixins/constants";
import { generateUrl } from '@nextcloud/router'
import { NcActions, NcActionButton, NcButton } from '@nextcloud/vue'

const MAX_PHOTO_WIDTH = 175;
const MIN_COLS = 3;

export default {
    components: {
        Folder,
        Photo,
        NcActions,
        NcActionButton,
        NcButton
    },
    data() {
        return {
            /** Loading days response */
            loading: true,
            /** Main list of rows */
            list: [],
            /** Counter of rows */
            numRows: 0,
            /** Computed number of columns */
            numCols: 5,
            /** Header rows for dayId key */
            heads: {},
            /** Original days response */
            days: [],

            /** Computed row height */
            rowHeight: 100,
            /** Total height of recycler */
            viewHeight: 1000,
            /** Total height of timeline */
            timelineHeight: 100,
            /** Computed timeline ticks */
            timelineTicks: [],
            /** Computed timeline cursor top */
            timelineCursorY: 0,
            /** Timeline hover cursor top */
            timelineHoverCursorY: -5,
            /** Timeline hover cursor text */
            timelineHoverCursorText: "",

            /** Current start index */
            currentStart: 0,
            /** Current end index */
            currentEnd: 0,
            /** Scrolling currently */
            scrolling: false,
            /** Scrolling timer */
            scrollTimer: null,
            /** Resizing timer */
            resizeTimer: null,
            /** Is mobile layout */
            isMobile: false,

            /** List of selected file ids */
            selection: new Set(),

            /** State for request cancellations */
            state: Math.random(),

            /** Constants for HTML template */
            c: constants,
        }
    },

    mounted() {
        this.handleResize();
        this.fetchDays();

        // Timeline recycler init
        this.$refs.recycler.$el.addEventListener('scroll', this.scrollPositionChange, false);
        this.scrollPositionChange();
    },

    watch: {
        $route(from, to) {
            console.log('route changed', from, to)
            this.resetState();
            this.fetchDays();
        },
    },

    beforeDestroy() {
        this.resetState();
    },

    methods: {
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
        },

        /** Do resize after some time */
        handleResizeWithDelay() {
            if (this.resizeTimer) {
                clearTimeout(this.resizeTimer);
            }
            this.resizeTimer = setTimeout(() => {
                this.handleResize();
                this.resizeTimer = null;
            }, 300);
        },

        /** Handle window resize and initialization */
        handleResize() {
            let height = this.$refs.container.clientHeight;
            let width = this.$refs.container.clientWidth;
            this.timelineHeight = this.$refs.timelineScroll.clientHeight;
            this.$refs.recycler.$el.style.height = (height - 4) + 'px';

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
            this.handleViewSizeChange();
        },

        /** Handle change in rows and view size */
        handleViewSizeChange() {
            setTimeout(() => {
                this.viewHeight = this.$refs.recycler.$refs.wrapper.clientHeight;

                // Compute timeline tick positions
                for (const tick of this.timelineTicks) {
                    tick.topC = Math.floor((tick.topS + tick.top * this.rowHeight) * this.timelineHeight / this.viewHeight);
                }

                // Do another pass to figure out which timeline points are visible
                // This is not as bad as it looks, it's actually 12*O(n)
                // because there are only 12 months in a year
                const minGap = parseFloat(getComputedStyle(this.$refs.cursorSt).fontSize) + (this.isMobile ? 5 : 2);
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
            }, 0);
        },

        /**
         * Triggered when position of scroll change.
         * This does NOT indicate the items have changed, only that
         * the pixel position of the recycler has changed.
         */
        scrollPositionChange(event) {
            if (event) {
                this.timelineCursorY = event.target.scrollTop * this.timelineHeight / this.viewHeight;
                this.timelineMoveHoverCursor(this.timelineCursorY);
            }

            if (this.scrollTimer) {
                clearTimeout(this.scrollTimer);
            }
            this.scrolling = true;
            this.scrollTimer = setTimeout(() => {
                this.scrolling = false;
                this.scrollTimer = null;
            }, 1500);
        },

        /** Trigger when recycler view changes */
        scrollChange(startIndex, endIndex) {
            if (startIndex === this.currentStart && endIndex === this.currentEnd) {
                return;
            }

            // Reset image state
            for (let i = startIndex; i < endIndex; i++) {
                if ((i < this.currentStart || i > this.currentEnd) && this.list[i].photos) {
                    this.list[i].photos.forEach(photo => {
                        photo.l = 0;
                    });
                }
            }

            // Make sure we don't do this too often
            this.currentStart = startIndex;
            this.currentEnd = endIndex;
            setTimeout(() => {
                if (this.currentStart === startIndex && this.currentEnd === endIndex) {
                    this.loadScrollChanges(startIndex, endIndex);
                }
            }, 300);
        },

        /** Load image data for given view */
        loadScrollChanges(startIndex, endIndex) {
            for (let i = startIndex; i <= endIndex; i++) {
                let item = this.list[i];
                if (!item) {
                    continue;
                }

                let head = this.heads[item.dayId];
                if (head && !head.loadedImages) {
                    head.loadedImages = true;
                    this.fetchDay(item.dayId);
                }
            }
        },

        /** Fetch timeline main call */
        async fetchDays() {
            let url = '/apps/memories/api/days';

            if (this.$route.name === 'folders') {
                const id = this.$route.params.id || 0;
                url = `/apps/memories/api/folder/${id}`;
            }

            const startState = this.state;
            const res = await axios.get(generateUrl(url));
            const data = res.data;
            if (this.state !== startState) return;

            this.days = data;

            for (const day of data) {
                day.count = Number(day.count);
                day.rows = new Set();

                // Nothing here
                if (day.count === 0) {
                    continue;
                }

                // Make date string
                const dateTaken = new Date(Number(day.dayid)*86400*1000);
                let dateStr = dateTaken.toLocaleDateString("en-US", { dateStyle: 'full', timeZone: 'UTC' });
                if (dateTaken.getUTCFullYear() === new Date().getUTCFullYear()) {
                    // hack: remove last 6 characters of date string
                    dateStr = dateStr.substring(0, dateStr.length - 6);
                }

                // Special headers
                if (day.dayid === -0.1) {
                    dateStr = "Folders";
                }

                // Add header to list
                const head = {
                    id: ++this.numRows,
                    name: dateStr,
                    size: 40,
                    head: true,
                    loadedImages: false,
                    dayId: day.dayid,
                    day: day,
                };
                this.heads[day.dayid] = head;
                this.list.push(head);

                // Add rows
                const nrows = Math.ceil(day.count / this.numCols);
                for (let i = 0; i < nrows; i++) {
                    const row = this.getBlankRow(day);
                    this.list.push(row);
                    day.rows.add(row);

                    // Add placeholders
                    const leftNum = (day.count - i * this.numCols);
                    const rowCount = leftNum > this.numCols ? this.numCols : leftNum;
                    for (let j = 0; j < rowCount; j++) {
                        row.photos.push({
                            flag: constants.FLAG_PLACEHOLDER,
                            fileid: `${day.dayid}-${i}-${j}`,
                        });
                    }
                }
            }

            // Check preloads
            for (const day of data) {
                if (day.count && day.detail) {
                    this.processDay(day);
                }
            }

            // Fix view height variable
            this.reflowTimeline();
            this.handleViewSizeChange();
            this.loading = false;
        },

        /** Fetch image data for one dayId */
        async fetchDay(dayId) {
            let url = `/apps/memories/api/days/${dayId}`;

            if (this.$route.name === 'folders') {
                const id = this.$route.params.id || 0;
                url = `/apps/memories/api/folder/${id}/${dayId}`;
            }

            // Do this in advance to prevent duplicate requests
            const head = this.heads[dayId];
            head.loadedImages = true;

            try {
                const startState = this.state;
                const res = await axios.get(generateUrl(url));
                const data = res.data;
                if (this.state !== startState) return;

                const day = this.days.find(d => d.dayid === dayId);
                day.detail = data;
                day.count = data.length;
                this.processDay(day, true);
            } catch (e) {
                console.error(e);
                head.loadedImages = false;
            }
        },

        /** Create timeline tick data */
        reflowTimeline() {
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
                const dateTaken = new Date(Number(day.dayid)*86400*1000);

                // Create tick if month changed
                const dtYear = dateTaken.getUTCFullYear();
                const dtMonth = dateTaken.getUTCMonth()
                if (Number.isInteger(day.dayid) && (dtMonth !== prevMonth || dtYear !== prevYear)) {
                    // Format dateTaken as MM YYYY
                    const dateTimeFormat = new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        timeZone: 'UTC',
                    });
                    const monthName = dateTimeFormat.formatToParts(dateTaken)[0].value;

                    // Create tick
                    this.timelineTicks.push({
                        dayId: day.id,
                        top: currTopRow,
                        topS: currTopStatic,
                        topC: 0,
                        text: (dtYear === prevYear || dtYear === thisYear) ? undefined : dtYear,
                        mText: `${monthName} ${dtYear}`,
                    });
                }
                prevMonth = dtMonth;
                prevYear = dtYear;

                currTopStatic += this.heads[day.dayid].size;
                currTopRow += day.rows.size;
            }
        },

        /**
         * Process items from day response.
         * Do not auto reflow if you plan to cal the reflow function later.
         *
         * @param {any} day Day object
         * @param {boolean} autoReflowTimeline Whether to reflow timeline if row changed
         */
        processDay(day, autoReflowTimeline = false) {
            const dayId = day.dayid;
            const data = day.detail;

            const head = this.heads[dayId];
            head.loadedImages = true;

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
            if (autoReflowTimeline && (addedRow || spliceCount > 0)) {
                this.reflowTimeline();
                this.handleViewSizeChange();
            }
        },

        /** Get a new blank row */
        getBlankRow(day) {
            return {
                id: ++this.numRows,
                photos: [],
                size: this.rowHeight,
                dayId: day.dayid,
                day: day,
            };
        },

        timelineMoveHoverCursor(y) {
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
            this.timelineHoverCursorText = this.timelineTicks[idx].mText;
        },

        /** Handle mouse hover on right timeline */
        timelineHover(event) {
            if (event.buttons) {
                this.timelineClick(event);
            }
            this.timelineMoveHoverCursor(event.offsetY);
        },

        /** Handle mouse leave on right timeline */
        timelineLeave() {
            this.timelineMoveHoverCursor(this.timelineCursorY);
        },

        /** Handle mouse click on right timeline */
        timelineClick(event) {
            this.$refs.recycler.scrollToPosition(this.getTimelinePosition(event.offsetY));
        },

        /** Handle touch on right timeline */
        timelineTouch(event) {
            const rect = event.target.getBoundingClientRect();
            const y = event.targetTouches[0].pageY - rect.top;
            this.$refs.recycler.scrollToPosition(this.getTimelinePosition(y));
            event.preventDefault();
            event.stopPropagation();
        },

        /** Get recycler equivalent position from event */
        getTimelinePosition(y) {
            const tH = this.viewHeight;
            const maxH = this.timelineHeight;
            return y * tH / maxH;
        },

        /** Scroll to given day Id */
        scrollToDay(dayId) {
            const head = this.heads[dayId];
            if (!head) {
                return;
            }
            this.$refs.recycler.scrollToPosition(1000);
        },

        /** Clicking on photo */
        clickPhoto(photoComponent) {
            if (this.selection.size > 0) { // selection mode
                photoComponent.toggleSelect();
            } else {
                photoComponent.openFile();
            }
        },

        /** Add a photo to selection list */
        selectPhoto(photo) {
            const nval = !this.selection.has(photo);
            if (nval) {
                photo.flag |= constants.FLAG_SELECTED;
                this.selection.add(photo);
            } else {
                photo.flag &= ~constants.FLAG_SELECTED;
                this.selection.delete(photo);
            }
            this.$forceUpdate();
        },

        /** Clear all selected photos */
        clearSelection() {
            for (const photo of this.selection) {
                photo.flag &= ~constants.FLAG_SELECTED;
            }
            this.selection.clear();
            this.$forceUpdate();
        },

        /** Delete all selected photos */
        async deleteSelection() {
            if (this.selection.size === 0) {
                return;
            }

            // Get files to delete
            const updatedDays = new Set();
            const delIds = new Set();
            for (const photo of this.selection) {
                if (!photo.fileid) {
                    continue;
                }
                delIds.add(photo.fileid);
                updatedDays.add(photo.d);
            }

            // Get files data
            let fileInfos = [];
            this.loading = true;
            try {
                fileInfos = await dav.getFiles([...delIds]);
            } catch {
                this.loading = false;
                alert('Failed to get file info');
                return;
            }

            // Run all promises together
            const promises = [];

            // Delete each file
            delIds.clear();
            for (const fileInfo of fileInfos) {
                promises.push((async () => {
                    try {
                        await dav.deleteFile(fileInfo.filename)
                        delIds.add(fileInfo.fileid);
                    } catch {
                        console.warn('Failed to delete', fileInfo.filename)
                    }
                })());
            }

            await Promise.allSettled(promises);
            this.loading = false;

            await this.deleteFromViewWithAnimation(delIds, updatedDays);
        },

        /** Download the selected files */
        async downloadSelection() {
            if (this.selection.size === 0) {
                return;
            }

            // Get files to download
            const fileInfos = await dav.getFiles([...this.selection].map(p => p.fileid));
            await dav.downloadFiles(fileInfos.map(f => f.filename));
        },

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
            exitedLeft.forEach((photo) => {
                photo.flag &= ~constants.FLAG_EXIT_LEFT;
                photo.flag |= constants.FLAG_ENTER_RIGHT;
            });

            // clear selection at this point
            this.clearSelection();

            // wait for 200ms
            await new Promise(resolve => setTimeout(resolve, 200));

            // Clear enter right flags
            exitedLeft.forEach((photo) => {
                photo.flag &= ~constants.FLAG_ENTER_RIGHT;
            });

            // Reflow timeline
            this.reflowTimeline();
            this.handleViewSizeChange();
        },
    },
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