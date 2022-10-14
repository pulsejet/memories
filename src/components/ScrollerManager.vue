<template>
    <div class="scroller"
        v-bind:class="{
            'scrolling-recycler': scrollingRecycler,
            'scrolling': scrolling,
        }"
        @mousemove="mousemove"
        @touchmove="touchmove"
        @mouseleave="mouseleave"
        @mousedown="mousedown">

        <span class="cursor st" ref="cursorSt"
                :style="{ transform: `translateY(${cursorY}px)` }">
        </span>

        <span class="cursor hv"
                :style="{ transform: `translateY(${hoverCursorY}px)` }">
                <div class="text"> {{ hoverCursorText }} </div>
                <div class="icon"> <ScrollIcon :size="20" /> </div>
        </span>

        <div v-for="tick of visibleTicks" :key="tick.dayId"
             class="tick"
            :class="{ 'dash': !tick.text }"
            :style="{ top: tick.topC + 'px' }">

            <span v-if="tick.text">{{ tick.text }}</span>
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Mixins, Prop } from 'vue-property-decorator';
import { IDay, IHeadRow, ITick } from '../types';
import GlobalMixin from '../mixins/GlobalMixin';
import ScrollIcon from 'vue-material-design-icons/UnfoldMoreHorizontal.vue';

import * as utils from "../services/Utils";

@Component({
    components: {
        ScrollIcon,
    },
})
export default class ScrollerManager extends Mixins(GlobalMixin) {
    /** Days from Timeline */
    @Prop() days!: IDay[];
    /** Heads from Timeline */
    @Prop() heads!: { [dayid: number]: IHeadRow };
    /** Total height */
    @Prop() height!: number;
    /** Height of a row */
    @Prop() rowHeight!: number;
    /** Actual recycler component */
    @Prop() recycler!: any;
    /** Recycler before slot component */
    @Prop() recyclerBefore!: any;

    /** Height of the entire photo view */
    private recyclerHeight!: number;
    /** Computed ticks */
    private ticks: ITick[] = [];
    /** Computed cursor top */
    private cursorY = 0;
    /** Hover cursor top */
    private hoverCursorY = -5;
    /** Hover cursor text */
    private hoverCursorText = "";
    /** Scrolling currently */
    private scrolling = false;
    /** Scrolling timer */
    private scrollingTimer = null as number | null;
    /** Scrolling recycler currently */
    private scrollingRecycler = false;
    /** Scrolling recycler timer */
    private scrollingRecyclerTimer = null as number | null;
    /** View size reflow timer */
    private reflowRequest = false;

    /** Get the visible ticks */
    get visibleTicks() {
        return this.ticks.filter(tick => tick.s);
    }

    /** Reset state */
    public reset() {
        this.ticks = [];
        this.cursorY = 0;
        this.hoverCursorY = -5;
        this.hoverCursorText = "";
        this.scrolling = false;
        this.scrollingTimer = null;
        this.reflowRequest = false;
    }

    /** Recycler scroll event, must be called by timeline */
    public recyclerScrolled(event?: any) {
        this.cursorY = event ? event.target.scrollTop * this.height / this.recyclerHeight : 0;
        this.moveHoverCursor(this.cursorY);

        if (this.scrollingRecyclerTimer) window.clearTimeout(this.scrollingRecyclerTimer);
        this.scrollingRecycler = true;
        this.scrollingRecyclerTimer = window.setTimeout(() => {
            this.scrollingRecycler = false;
            this.scrollingRecyclerTimer = null;
        }, 1500);
    }

    /** Re-create tick data in the next frame */
    public async reflow(orderOnly = false) {
        if (this.reflowRequest) {
            return;
        }

        this.reflowRequest = true;
        await this.$nextTick();
        this.reflowNow(orderOnly);
        this.reflowRequest = false;
    }

    /** Re-create tick data */
    private reflowNow(orderOnly = false) {
        if (!orderOnly) {
            this.recreate();
        }

        this.recyclerHeight = this.recycler.$refs.wrapper.clientHeight;

        // Static extra height at top
        const rb = this.recyclerBefore as Element;
        const extraHeight = rb?.clientHeight || 0;

        // Compute tick positions
        for (const tick of this.ticks) {
            tick.topC = (extraHeight + tick.topS + tick.top * this.rowHeight) * this.height / this.recyclerHeight;
        }

        // Do another pass to figure out which points are visible
        // This is not as bad as it looks, it's actually 12*O(n)
        // because there are only 12 months in a year
        const fontSizePx = parseFloat(getComputedStyle(this.$refs.cursorSt as any).fontSize);
        const minGap = fontSizePx + (window.innerWidth <= 768 ? 5 : 2);
        let prevShow = -9999;
        for (const [idx, tick] of this.ticks.entries()) {
            // You can't see these anyway, why bother?
            if (tick.topC < minGap || tick.topC > this.height - minGap) {
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
            while(i < this.ticks.length) {
                if (this.ticks[i].text) {
                    break;
                }
                i++;
            }
            if (i < this.ticks.length) {
                // A labelled tick was found
                const nextLabelledTick = this.ticks[i];
                if (tick.topC + minGap > nextLabelledTick.topC &&
                    nextLabelledTick.topC < this.height - minGap) { // make sure this will be shown
                    tick.s = false;
                    continue;
                }
            }

            // Show this tick
            tick.s = true;
            prevShow = tick.topC;
        }
    }

    /** Recreate from scratch */
    private recreate() {
        // Clear
        this.ticks = [];

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
                this.ticks.push(getTick(day));
            } else {
                // Make date string
                const dateTaken = utils.dayIdToDate(day.dayid);

                // Create tick if month changed
                const dtYear = dateTaken.getUTCFullYear();
                const dtMonth = dateTaken.getUTCMonth()
                if (Number.isInteger(day.dayid) && (dtMonth !== prevMonth || dtYear !== prevYear)) {
                    this.ticks.push(getTick(day, (dtYear === prevYear || dtYear === thisYear) ? undefined : dtYear));
                }
                prevMonth = dtMonth;
                prevYear = dtYear;
            }

            currTopStatic += this.heads[day.dayid].size;
            currTopRow += day.rows.size;
        }
    }

    /** Change actual position of the hover cursor */
    private moveHoverCursor(y: number) {
        this.hoverCursorY = y;

        // Get index of previous tick
        let idx = this.ticks.findIndex(t => t.topC > y);
        if (idx >= 1) {
            idx = idx - 1;
        } else if (idx === -1 && this.ticks.length > 0) {
            idx = this.ticks.length - 1;
        } else {
            return;
        }

        // DayId of current hover
        const dayId = this.ticks[idx].dayId

        // Special days
        if (Object.values(this.TagDayID).includes(dayId)) {
            this.hoverCursorText = "";
            return;
        }

        const date = utils.dayIdToDate(dayId);
        this.hoverCursorText = utils.getShortDateStr(date);
    }

    /** Handle mouse hover */
    private mousemove(event: MouseEvent) {
        if (event.buttons) {
            this.mousedown(event);
        }
        this.moveHoverCursor(event.offsetY);
    }

    /** Handle mouse leave */
    private mouseleave() {
        this.moveHoverCursor(this.cursorY);
    }

    /** Handle mouse click */
    private mousedown(event: MouseEvent) {
        this.recycler.scrollToPosition(this.getRecyclerY(event.offsetY));
        this.handleScroll();
    }

    /** Handle touch */
    private touchmove(event: any) {
        const rect = event.target.getBoundingClientRect();
        const y = event.targetTouches[0].pageY - rect.top;
        this.recycler.scrollToPosition(this.getRecyclerY(y));
        event.preventDefault();
        event.stopPropagation();
        this.handleScroll();
    }

    /** Update this is being used to scroll recycler */
    private handleScroll() {
        if (this.scrollingTimer) window.clearTimeout(this.scrollingTimer);
        this.scrolling = true;
        this.scrollingTimer = window.setTimeout(() => {
            this.scrolling = false;
            this.scrollingTimer = null;
        }, 1500);
    }

    /** Get recycler equivalent position from event */
    private getRecyclerY(y: number) {
        const tH = this.recyclerHeight;
        const maxH = this.height;
        return y * tH / maxH;
    }
}
</script>

<style lang="scss" scoped>
@mixin phone {
  @media (max-width: 768px) { @content; }
}

.scroller {
    overflow-y: clip;
    position: absolute;
    height: 100%;
    width: 36px;
    top: 0; right: 0;
    cursor: ns-resize;
    opacity: 0;
    transition: opacity .2s ease-in-out;

    // Show on hover or scroll of main window
    &:hover, &.scrolling-recycler {
        opacity: 1;
    }

    // Hide ticks on mobile unless hovering
    @include phone {
        &:not(.scrolling) {
            .cursor.hv {
                left: 12px;
                border: none;
                box-shadow: 0 0 5px -3px #000;
                height: 30px;
                border-radius: 15px;
                > .text { display: none; }
                > .icon { display: block; }
            }
            > .tick { opacity: 0; }
        }
        .cursor.st { display: none; }
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
        will-change: transform;

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

            > .icon {
                display: none;
                transform: translate(-4px, 2px);
            }
        }
    }
    &:hover > .cursor.st {
        opacity: 1;
    }
}
</style>