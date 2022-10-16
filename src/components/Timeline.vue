<template>
    <div class="container" ref="container" :class="{ 'icon-loading': loading > 0 }">
        <!-- Static top matter -->
        <TopMatter ref="topmatter" />

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
            @update="scrollChange"
            @resize="handleResizeWithDelay"
        >
            <template #before>
                <!-- Show dynamic top matter, name of the view -->
                <div class="recycler-before" ref="recyclerBefore"
                     v-show="!$refs.topmatter.type && list.length > 0">
                    {{ getViewName() }}
                </div>
            </template>

            <template v-slot="{ item }">
                <div v-if="item.type === 0"
                     class="head-row"
                    :class="{ 'selected': item.selected }"
                    :style="{ height: item.size + 'px' }">

                    <div class="super" v-if="item.super !== undefined">
                        {{ item.super }}
                    </div>
                    <div class="main" @click="selectionManager.selectHead(item)">
                        <CheckCircle :size="18" class="select" />
                        <span class="name" > {{ item.name || getHeadName(item) }} </span>
                    </div>
                </div>

                <div v-else
                     class="photo-row"
                    :style="{ height: item.size + 'px', width: rowWidth + 'px' }">

                    <div class="photo" v-for="(photo, index) in item.photos" :key="photo.fileid"
                        :style="{ width: (photo.dispWp * 100) + '%' }">

                        <Folder v-if="photo.flag & c.FLAG_IS_FOLDER"
                                :data="photo"
                                :key="photo.fileid" />

                        <Tag v-else-if="photo.flag & c.FLAG_IS_TAG"
                                :data="photo"
                                :key="photo.fileid" />

                        <Photo v-else
                                :data="photo"
                                :day="item.day"
                                @select="selectionManager.selectPhoto"
                                @delete="deleteFromViewWithAnimation"
                                @clickImg="clickPhoto" />
                    </div>
                </div>
            </template>
        </RecycleScroller>

        <!-- Managers -->
        <ScrollerManager ref="scrollerManager"
            :rows="list"
            :height="scrollerHeight"
            :recycler="$refs.recycler"
            :recyclerBefore="$refs.recyclerBefore" />

        <SelectionManager ref="selectionManager"
            :selection="selection" :heads="heads"
            @refresh="refresh"
            @delete="deleteFromViewWithAnimation"
            @updateLoading="updateLoading" />
    </div>
</template>

<script lang="ts">
import { Component, Watch, Mixins } from 'vue-property-decorator';
import { IDay, IFolder, IHeadRow, IPhoto, IRow, IRowType } from "../types";
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { getCanonicalLocale } from '@nextcloud/l10n';
import { NcEmptyContent } from '@nextcloud/vue';
import GlobalMixin from '../mixins/GlobalMixin';
import moment from 'moment';

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";
import justifiedLayout from "justified-layout";
import axios from '@nextcloud/axios'
import Folder from "./frame/Folder.vue";
import Tag from "./frame/Tag.vue";
import Photo from "./frame/Photo.vue";
import TopMatter from "./top-matter/TopMatter.vue";
import SelectionManager from './SelectionManager.vue';
import ScrollerManager from './ScrollerManager.vue';
import UserConfig from "../mixins/UserConfig";

import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import PeopleIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';

const SCROLL_LOAD_DELAY = 100;          // Delay in loading data when scrolling
const DESKTOP_ROW_HEIGHT = 200;         // Height of row on desktop
const MOBILE_NUM_COLS = 3;              // Number of columns on phone

@Component({
    components: {
        Folder,
        Tag,
        Photo,
        TopMatter,
        SelectionManager,
        ScrollerManager,
        NcEmptyContent,

        CheckCircle,
        ArchiveIcon,
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
    private numCols = 0;
    /** Keep all images square */
    private squareMode = false;
    /** Header rows for dayId key */
    private heads: { [dayid: number]: IHeadRow } = {};
    /** Original days response */
    private days: IDay[] = [];

    /** Computed row height */
    private rowHeight = 100;
    /** Computed row width */
    private rowWidth = 100;

    /** Current start index */
    private currentStart = 0;
    /** Current end index */
    private currentEnd = 0;
    /** Resizing timer */
    private resizeTimer = null as number | null;
    /** Height of the scroller */
    private scrollerHeight = 100;

    /** Set of dayIds for which images loaded */
    private loadedDays = new Set<number>();
    /** Set of selected file ids */
    private selection = new Map<number, IPhoto>();

    /** State for request cancellations */
    private state = Math.random();

    /** Selection manager component */
    private selectionManager!: SelectionManager & any;
    /** Scroller manager component */
    private scrollerManager!: ScrollerManager & any;

    mounted() {
        this.selectionManager = this.$refs.selectionManager;
        this.scrollerManager = this.$refs.scrollerManager;
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

    updateLoading(delta: number) {
        this.loading += delta;
    }

    /** Create new state */
    async createState() {
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
        this.selectionManager.clearSelection();
        this.loading = 0;
        this.list = [];
        this.numRows = 0;
        this.heads = {};
        this.days = [];
        this.currentStart = 0;
        this.currentEnd = 0;
        this.scrollerManager.reset();
        this.state = Math.random();
        this.loadedDays.clear();
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
        // Size of outer container
        const e = this.$refs.container as Element;
        let height = e.clientHeight;
        this.rowWidth = e.clientWidth;

        // Scroller spans the container height
        this.scrollerHeight = height;

        // Static top matter to exclude from recycler height
        const topmatter = this.$refs.topmatter as any;
        const tmHeight = topmatter.$el?.clientHeight || 0;

        // Recycler height
        const recycler = this.$refs.recycler as any;
        recycler.$el.style.height = (height - tmHeight - 4) + 'px';

        if (window.innerWidth <= 768) {
            // Mobile
            this.numCols = MOBILE_NUM_COLS;
            this.rowHeight = Math.floor(this.rowWidth / this.numCols);
            this.squareMode = true;
        } else {
            // Desktop
            this.rowWidth -= 40;
            this.squareMode = this.config_squareThumbs;

            if (this.squareMode) {
                // Set columns first, then height
                this.numCols = Math.max(3, Math.floor(this.rowWidth / DESKTOP_ROW_HEIGHT));
                this.rowHeight = Math.floor(this.rowWidth / this.numCols);
            } else {
                // As a heuristic, assume all images are 4:3 landscape
                this.rowHeight = DESKTOP_ROW_HEIGHT;
                this.numCols = Math.floor(this.rowWidth / (this.rowHeight * 4 / 3));
            }
        }

        this.scrollerManager.reflow();
    }

    /**
     * Triggered when position of scroll change.
     * This does NOT indicate the items have changed, only that
     * the pixel position of the recycler has changed.
     */
    scrollPositionChange(event?: any) {
        this.scrollerManager.recyclerScrolled(event)
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
                    // Any row that has placeholders has ONLY placeholders
                    // so we can calculate the display width
                    row.photos[j] = {
                        flag: this.c.FLAG_PLACEHOLDER,
                        fileid: Math.random(),
                        dispWp: 1 / this.numCols,
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

    /** Store the current scroll position to restore later */
    private getScrollY() {
        const recycler = this.$refs.recycler as any;
        return recycler.$el.scrollTop
    }

    /** Restore the stored scroll position */
    private setScrollY(y: number) {
        const recycler = this.$refs.recycler as any;
        recycler.scrollToPosition(y);
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
        if (this.$route.name === 'archive') {
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

    /** Get view name for dynamic top matter */
    getViewName() {
        switch (this.$route.name) {
            case 'timeline': return this.t('memories', 'Your Timeline');
            case 'favorites': return this.t('memories', 'Favorites');
            case 'people': return this.t('memories', 'People');
            case 'videos': return this.t('memories', 'Videos');
            case 'archive': return this.t('memories', 'Archive');
            case 'thisday': return this.t('memories', 'On this day');
            case 'tags': return this.t('memories', 'Tags');
            default: return '';
        }
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
        } else if (head.dayId === this.TagDayID.TAGS || head.dayId === this.TagDayID.FACES) {
            return (head.name = "");
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
        let url = '/apps/memories/api/days';
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

        let prevDay: IDay | null = null;
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

            // Create header for this day
            const head: IHeadRow = {
                id: ++this.numRows,
                size: 40,
                type: IRowType.HEAD,
                selected: false,
                dayId: day.dayid,
                day: day,
            };

            // Special headers
            if (day.dayid === this.TagDayID.TAGS    ||
                day.dayid === this.TagDayID.FACES) {
                head.size = 10;
            } else if (this.$route.name === 'thisday' && (!prevDay || Math.abs(prevDay.dayid - day.dayid) > 30)) {
                // thisday view with new year title
                head.size = 67;
                const dateTaken = moment(utils.dayIdToDate(day.dayid));
                const text = dateTaken.locale(getCanonicalLocale()).fromNow();
                head.super = text.charAt(0).toUpperCase() + text.slice(1);
            }

            // Add header to list
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

            // Continue processing
            prevDay = day;
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
        await this.scrollerManager.reflow();
    }

    /** Fetch image data for one dayId */
    async fetchDay(dayId: number) {
        let url = '/apps/memories/api/days/{dayId}';
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

    /**
     * Process items from day response.
     *
     * @param day Day object
     */
    processDay(day: IDay) {
        const dayId = day.dayid;
        const data = day.detail;

        // Create justified layout with correct params
        const justify = justifiedLayout(day.detail.map(p => {
            return {
                width: (this.squareMode ? null : p.w) || this.rowHeight,
                height: (this.squareMode ? null : p.h) || this.rowHeight,
            };
        }), {
            containerWidth: this.rowWidth,
            containerPadding: 0,
            boxSpacing: 0,
            targetRowHeight: this.rowHeight,
            targetRowHeightTolerance: 0.1,
        });

        const head = this.heads[dayId];
        this.loadedDays.add(dayId);

        // Reset rows including placeholders
        if (head.day?.rows) {
            for (const row of head.day.rows) {
                row.photos = [];
            }
        }
        head.day.rows.clear();

        // Check if some rows were added
        let addedRows: IRow[] = [];

        // Check if row height changed
        let rowSizeDelta = 0;

        // Get index of header O(n)
        const headIdx = this.list.findIndex(item => item.id === head.id);
        let rowIdx = headIdx + 1;

        // Store the scroll position in case we change any rows
        const scrollY = this.getScrollY();

        // Previous justified row
        let prevJustifyTop = justify.boxes[0]?.top || 0;

        // Add all rows
        let dataIdx = 0;
        while (dataIdx < data.length) {
            // Check if we ran out of rows
            if (rowIdx >= this.list.length || this.list[rowIdx].type === IRowType.HEAD) {
                const newRow = this.getBlankRow(day);
                addedRows.push(newRow);
                rowSizeDelta += newRow.size;
                this.list.splice(rowIdx, 0, newRow);
            }

            // Go to the next row
            const jbox = justify.boxes[dataIdx];
            if (jbox.top !== prevJustifyTop) {
                prevJustifyTop = jbox.top;
                rowIdx++;
                continue;
            }

            // Set row height
            const row = this.list[rowIdx];
            rowSizeDelta += jbox.height - row.size;
            row.size = jbox.height;

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

            // Get aspect ratio
            photo.dispWp = jbox.width / this.rowWidth;

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

        // Rows that were removed
        const removedRows: IRow[] = [];
        let headRemoved = false;

        // No rows, splice everything including the header
        if (head.day.rows.size === 0) {
            removedRows.push(...this.list.splice(headIdx, 1));
            rowIdx = headIdx - 1;
            headRemoved = true;
            delete this.heads[dayId];
        }

        // Get rid of any extra rows
        let spliceCount = 0;
        for (let i = rowIdx + 1; i < this.list.length && this.list[i].type !== IRowType.HEAD; i++) {
            spliceCount++;
        }
        if (spliceCount > 0) {
            removedRows.push(...this.list.splice(rowIdx + 1, spliceCount));
        }

        // Update size delta for removed rows
        for (const row of removedRows) {
            rowSizeDelta -= row.size;
        }

        // This will be true even if the head is being spliced
        // because one row is always removed in that case
        // So just reflow the timeline here
        if (rowSizeDelta !== 0) {
            if (headRemoved) {
                // If the head was removed, that warrants a reflow
                // since months or years might disappear!
                this.scrollerManager.reflow();
            } else {
                // Otherwise just adjust the visible ticks
                this.scrollerManager.adjust();
            }

            // Scroll to the same actual position if the added rows
            // were above the current scroll position
            const recycler: any = this.$refs.recycler;
            const midIndex = (recycler.$_startIndex + recycler.$_endIndex) / 2;
            if (midIndex > headIdx) {
                this.setScrollY(scrollY + rowSizeDelta);
            }
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

    /** Clicking on photo */
    clickPhoto(photoComponent: any) {
        if (this.selection.size > 0) { // selection mode
            photoComponent.toggleSelect();
        } else {
            photoComponent.openFile();
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
        this.selectionManager.clearSelection(delPhotos);

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
    height: 100%;
}

.head-row {
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
        &.main { font-weight: 600; }
    }

    .select {
        position: absolute;
        left: 0; top: 50%;
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
    }

    .hover &, &.selected {
        .select {
            display: flex;
            opacity: 0.7;
        }
        .name {
            transform: translateX(22px);
        }
    }
    &.selected .select { opacity: 1; }

    @include phone { transform: translateX(8px); }
}

/** Static and dynamic top matter */
.top-matter {
    padding-top: 4px;
    @include phone { padding-left: 40px; }
}
.recycler-before {
    font-size: 1.2em;
    padding-top: 13px;
    padding-left: 8px;
    @include phone { padding-left: 48px; }
}
</style>