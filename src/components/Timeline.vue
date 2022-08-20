<template>
    <div class="container" ref="container" :class="{ 'icon-loading': loading }">
        <RecycleScroller
            ref="scroller"
            class="scroller"
            :items="list"
            size-field="size"
            key-field="id"
            v-slot="{ item }"
            :emit-update="true"
            @update="scrollChange"
            @resize="handleResizeWithDelay"
        >
            <h1 v-if="item.head" class="head-row" v-bind:class="{ 'first': item.id === 1 }">
                {{ item.name }}
            </h1>

            <div v-else
                class="photo-row"
                v-bind:style="{ height: rowHeight + 'px' }">

                <div class="photo" v-for="photo of item.photos">
                    <Folder v-if="photo.is_folder"
                            :data="photo" :rowHeight="rowHeight" />
                    <Photo v-else
                            :data="photo" :rowHeight="rowHeight" :day="item.day" />
                </div>
            </div>
        </RecycleScroller>

        <div ref="timelineScroll" class="timeline-scroll"
             v-bind:class="{ scrolling }"
            @mousemove="timelineHover"
            @touchmove="timelineTouch"
            @mouseleave="timelineLeave"
            @mousedown="timelineClick">
            <span class="cursor st" ref="cursorSt"
                  v-bind:style="{ top: timelineCursorY + 'px' }"></span>
            <span class="cursor hv"
                  v-bind:style="{ transform: `translateY(${timelineHoverCursorY}px)` }">
                  {{ timelineHoverCursorText }}
            </span>

            <div v-for="tick of timelineTicks" :key="tick.dayId" class="tick"
                v-bind:class="{ 'dash': !tick.text }"
                v-bind:style="{ top: tick.topC + 'px' }">

                <template v-if="tick.s">
                    <span v-if="tick.text">{{ tick.text }}</span>
                    <span v-else class="dash"></span>
                </template>
            </div>
        </div>
    </div>
</template>

<script>

import axios from '@nextcloud/axios'
import Folder from "./Folder";
import Photo from "./Photo";
import { generateUrl } from '@nextcloud/router'

const MAX_PHOTO_WIDTH = 175;
const MIN_COLS = 3;

export default {
    components: {
        Folder,
        Photo,
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

            /** State for request cancellations */
            state: Math.random(),
        }
    },

    mounted() {
        this.handleResize();
        this.fetchDays();

        // Timeline scroller init
        this.$refs.scroller.$el.addEventListener('scroll', this.scrollPositionChange, false);
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
            this.$refs.scroller.$el.style.height = (height - 4) + 'px';

            // Mobile devices
            if (width < 768) {
                width += 10;
            } else {
                width -= 12;
            }

            if (this.days.length === 0) {
                // Don't change cols if already initialized
                this.numCols = Math.max(MIN_COLS, Math.floor(width / MAX_PHOTO_WIDTH));
            }

            this.rowHeight = Math.floor(width / this.numCols) - 4;

            // Set heights of rows
            this.list.filter(r => !r.head).forEach(row => {
                row.size = this.rowHeight;
            });
            this.handleViewSizeChange();
        },

        /** Handle change in rows and view size */
        handleViewSizeChange() {
            setTimeout(() => {
                this.viewHeight = this.$refs.scroller.$refs.wrapper.clientHeight;

                // Compute timeline tick positions
                for (const tick of this.timelineTicks) {
                    tick.topC = Math.floor((tick.topS + tick.top * this.rowHeight) * this.timelineHeight / this.viewHeight);
                }

                // Do another pass to figure out which timeline points are visible
                // This is not as bad as it looks, it's actually 12*O(n)
                // because there are only 12 months in a year
                const minGap = parseFloat(getComputedStyle(this.$refs.cursorSt).fontSize) + 2;
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
         * the pixel position of the scroller has changed.
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

            if (this.$route.name === 'albums') {
                const id = this.$route.params.id || 0;
                url = `/apps/memories/api/folder/${id}`;
            }

            const startState = this.state;
            const res = await axios.get(generateUrl(url));
            const data = res.data;
            if (this.state !== startState) return;

            this.days = data;

            // Ticks
            let currTopRow = 0;
            let currTopStatic = 0;
            let prevYear = 9999;
            let prevMonth = 0;
            const thisYear = new Date().getFullYear();

            for (const day of data) {
                day.count = Number(day.count);

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

                // Create tick if month changed
                const dtYear = dateTaken.getUTCFullYear();
                const dtMonth = dateTaken.getUTCMonth()
                if (Number.isInteger(day.dayid) && (dtMonth !== prevMonth || dtYear !== prevYear)) {
                    // Format dateTaken as MM YYYY
                    const dateTimeFormat = new Intl.DateTimeFormat('en-US', { month: 'short' });
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
                currTopStatic += head.size;

                // Add rows
                const nrows = Math.ceil(day.count / this.numCols);
                for (let i = 0; i < nrows; i++) {
                    const row = this.getBlankRow(day);
                    this.list.push(row);

                    // Add placeholders
                    const leftNum = (day.count - i * this.numCols);
                    const rowCount = leftNum > this.numCols ? this.numCols : leftNum;
                    for (let j = 0; j < rowCount; j++) {
                        row.photos.push({
                            ph: true, // placeholder
                            fileid: `${day.dayid}-${i}-${j}`,
                        });
                    }

                    // Increment timeline scroller top
                    currTopRow++;
                }
            }

            // Check preloads
            for (const day of data) {
                if (day.count && day.detail) {
                    this.processDay(day.dayid, day.detail);
                }
            }

            // Fix view height variable
            this.handleViewSizeChange();
            this.loading = false;
        },

        /** Fetch image data for one dayId */
        async fetchDay(dayId) {
            let url = `/apps/memories/api/days/${dayId}`;

            if (this.$route.name === 'albums') {
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

                this.days.find(d => d.dayid === dayId).detail = data;
                this.processDay(dayId, data);
            } catch (e) {
                console.error(e);
                head.loadedImages = false;
            }
        },

        /** Process items from day response */
        processDay(dayId, data) {
            const head = this.heads[dayId];
            head.loadedImages = true;

            // Get index of header O(n)
            const headIdx = this.list.findIndex(item => item.id === head.id);
            let rowIdx = headIdx + 1;

            // Add all rows
            let dataIdx = 0;
            while (dataIdx < data.length) {
                // Check if we ran out of rows
                if (rowIdx >= this.list.length || this.list[rowIdx].head) {
                    this.list.splice(rowIdx, 0, this.getBlankRow(day));
                }

                const row = this.list[rowIdx];
                if (row.photos.length > 0 && row.photos[0].ph) {
                    row.photos = [];
                    continue;
                }

                // Go to the next row
                if (row.photos.length >= this.numCols) {
                    rowIdx++;
                    continue;
                }

                // Add the photo to the row
                this.list[rowIdx].photos.push(data[dataIdx]);
                dataIdx++;
            }

            // Get rid of any extra rows
            let spliceCount = 0;
            for (let i = rowIdx + 1; i < this.list.length && !this.list[i].head; i++) {
                spliceCount++;
            }
            if (spliceCount > 0) {
                this.list.splice(rowIdx + 1, spliceCount);
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
            this.$refs.scroller.scrollToPosition(this.getTimelinePosition(event.offsetY));
        },

        /** Handle touch on right timeline */
        timelineTouch(event) {
            const rect = event.target.getBoundingClientRect();
            const y = event.targetTouches[0].pageY - rect.top;
            this.$refs.scroller.scrollToPosition(this.getTimelinePosition(y));
            event.preventDefault();
            event.stopPropagation();
        },

        /** Get scroller equivalent position from event */
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
            this.$refs.scroller.scrollToPosition(1000);
        },
    },
}
</script>

<style scoped>
.container {
    height: 100%;
    width: 100%;
    overflow: hidden;
}

.scroller {
    height: 300px;
    width: calc(100% + 20px);
}

.photo-row .photo {
    display: inline-block;
    position: relative;
    cursor: pointer;
}

.photo-row .photo::before {
    content: "";
    position: absolute;
    display: block;
    height: calc(100% - 4px);
    width: calc(100% - 4px);
    top: 2px; left: 2px;
    background: linear-gradient(0deg, rgba(0,0,0,0) 0%, rgba(0,0,0,0.3) 95%);
    opacity: 0;
    border-radius: 3px;
    transition: opacity .1s ease-in-out;
    pointer-events: none;
    user-select: none;
}
.photo-row .photo:hover::before {
    opacity: 1;
}

.head-row {
    height: 40px;
    padding-top: 10px;
    padding-left: 3px;
    font-size: 0.9em;
    font-weight: 600;
}

.timeline-scroll {
    overflow-y: clip;
    position: absolute;
    height: 100%;
    width: 40px;
    top: 0; right: 0;
    cursor: ns-resize;
    opacity: 0;
    transition: opacity .2s ease-in-out;
}
.timeline-scroll:hover, .timeline-scroll.scrolling {
    opacity: 1;
}

.timeline-scroll .tick {
    pointer-events: none;
    position: absolute;
    font-size: 0.8em;
    right: 5px;
    transform: translateY(-50%);
    z-index: 1;
}

.timeline-scroll .tick .dash {
    height: 4px;
    width: 4px;
    border-radius: 50%;
    background-color: var(--color-main-text);
    opacity: 0.5;
    display: block;
}

.timeline-scroll .cursor {
    position: absolute;
    pointer-events: none;
    right: 5px;
    background-color: var(--color-primary);
    min-width: 100%;
    min-height: 2px;
}

.timeline-scroll .cursor.st {
    font-size: 0.8em;
    opacity: 0;
}
.timeline-scroll:hover .cursor.st {
    opacity: 1;
}
.timeline-scroll .cursor.hv {
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

@media (max-width: 768px) {
    .timeline-scroll .tick {
        background-color: var(--color-main-background);
        padding: 1px 4px;
        border-radius: 4px;
    }
    .timeline-scroll .tick.dash {
        display: none;
    }
    .head-row.first {
        padding-left: 34px;
    }
}
</style>