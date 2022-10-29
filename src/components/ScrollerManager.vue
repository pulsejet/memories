<template>
  <div
    class="scroller"
    ref="scroller"
    v-bind:class="{
      'scrolling-recycler': scrollingRecyclerTimer,
      'scrolling-recycler-now': scrollingRecyclerNowTimer,
      scrolling: scrolling,
    }"
    @mousemove="mousemove"
    @touchmove="touchmove"
    @mouseleave="mouseleave"
    @mousedown="mousedown"
  >
    <span
      class="cursor st"
      ref="cursorSt"
      :style="{ transform: `translateY(${cursorY}px)` }"
    >
    </span>

    <span
      class="cursor hv"
      :style="{ transform: `translateY(${hoverCursorY}px)` }"
      @touchmove="touchmove"
    >
      <div class="text">{{ hoverCursorText }}</div>
      <div class="icon"><ScrollIcon :size="22" /></div>
    </span>

    <div
      v-for="tick of visibleTicks"
      :key="tick.key"
      class="tick"
      :class="{ dash: !tick.text }"
      :style="{ transform: `translateY(calc(${tick.top}px - 50%))` }"
    >
      <span v-if="tick.text">{{ tick.text }}</span>
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Mixins, Prop } from "vue-property-decorator";
import { IRow, IRowType, ITick } from "../types";
import GlobalMixin from "../mixins/GlobalMixin";
import ScrollIcon from "vue-material-design-icons/UnfoldMoreHorizontal.vue";

import * as utils from "../services/Utils";

@Component({
  components: {
    ScrollIcon,
  },
})
export default class ScrollerManager extends Mixins(GlobalMixin) {
  /** Rows from Timeline */
  @Prop() rows!: IRow[];
  /** Total height */
  @Prop() height!: number;
  /** Actual recycler component */
  @Prop() recycler!: any;
  /** Recycler before slot component */
  @Prop() recyclerBefore!: any;

  /** Last known height at adjustment */
  private lastAdjustHeight = 0;
  /** Height of the entire photo view */
  private recyclerHeight: number = 100;
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
  /** Scrolling recycler timer */
  private scrollingRecyclerTimer = 0;
  /** Scrolling recycler timer */
  private scrollingRecyclerNowTimer = 0;
  /** Recycler scrolling throttle */
  private scrollingRecyclerUpdateTimer = 0;
  /** View size reflow timer */
  private reflowRequest = false;
  /** Tick adjust timer */
  private adjustRequest = false;

  /** Get the visible ticks */
  get visibleTicks() {
    let key = 999900;
    return this.ticks
      .filter((tick) => tick.s)
      .map((tick) => {
        if (tick.text) {
          tick.key = key = tick.dayId * 100;
        } else {
          tick.key = ++key; // days are sorted descending
        }
        return tick;
      });
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
  public recyclerScrolled() {
    if (this.scrollingRecyclerUpdateTimer) return;
    this.scrollingRecyclerUpdateTimer = window.setTimeout(() => {
      this.scrollingRecyclerUpdateTimer = 0;
      requestAnimationFrame(this.updateFromRecyclerScroll);
    }, 100);
  }

  /** Update cursor position from recycler scroll position */
  public updateFromRecyclerScroll() {
    // Ignore if not initialized
    if (!this.ticks.length) return;

    // Get the scroll position
    const scroll = this.recycler?.$el?.scrollTop || 0;

    // Get cursor px position
    const { top1, top2, y1, y2 } = this.getCoords(scroll, "y");
    const topfrac = (scroll - y1) / (y2 - y1);
    const rtop = top1 + (top2 - top1) * topfrac;

    // Always move static cursor to right position
    this.cursorY = rtop;

    // Move hover cursor to same position unless hovering
    // Regardless, we need this call because the internal mapping might have changed
    if ((<HTMLElement>this.$refs.scroller).matches(":hover")) {
      this.moveHoverCursor(this.hoverCursorY);
    } else {
      this.moveHoverCursor(rtop);
    }

    // Animate the cursor
    if (this.scrollingRecyclerNowTimer)
      window.clearTimeout(this.scrollingRecyclerNowTimer);
    this.scrollingRecyclerNowTimer = window.setTimeout(() => {
      this.scrollingRecyclerNowTimer = 0;
    }, 200);

    // Show the scroller for some time
    if (this.scrollingRecyclerTimer)
      window.clearTimeout(this.scrollingRecyclerTimer);
    this.scrollingRecyclerTimer = window.setTimeout(() => {
      this.scrollingRecyclerTimer = 0;
    }, 1500);
  }

  /** Re-create tick data in the next frame */
  public async reflow() {
    if (this.reflowRequest) return;
    this.reflowRequest = true;
    await this.$nextTick();
    this.reflowNow();
    this.reflowRequest = false;
  }

  /** Re-create tick data */
  private reflowNow() {
    // Ignore if not initialized
    if (!this.recycler?.$refs.wrapper) return;

    // Refresh height of recycler
    this.recyclerHeight = this.recycler.$refs.wrapper.clientHeight;

    // Recreate ticks data
    this.recreate();

    // Adjust top
    this.adjustNow();
  }

  /** Recreate from scratch */
  private recreate() {
    // Clear and override any adjust timer
    this.ticks = [];

    // Ticks
    let prevYear = 9999;
    let prevMonth = 0;
    const thisYear = new Date().getFullYear();

    // Get a new tick
    const getTick = (
      dayId: number,
      isMonth = false,
      text?: string | number
    ): ITick => {
      return {
        dayId,
        isMonth,
        text,
        y: 0,
        count: 0,
        topF: 0,
        top: 0,
        s: false,
      };
    };

    // Iterate over rows
    for (const row of this.rows) {
      if (row.type === IRowType.HEAD) {
        // Create tick
        if (this.TagDayIDValueSet.has(row.dayId)) {
          // Blank tick
          this.ticks.push(getTick(row.dayId));
        } else {
          // Make date string
          const dateTaken = utils.dayIdToDate(row.dayId);

          // Create tick
          const dtYear = dateTaken.getUTCFullYear();
          const dtMonth = dateTaken.getUTCMonth();
          const isMonth = dtMonth !== prevMonth || dtYear !== prevYear;
          const text =
            dtYear === prevYear || dtYear === thisYear ? undefined : dtYear;
          this.ticks.push(getTick(row.dayId, isMonth, text));

          prevMonth = dtMonth;
          prevYear = dtYear;
        }
      }
    }
  }

  /**
   * Update tick positions without truncating the list
   * This is much cheaper than reflowing the whole thing
   */
  public async adjust() {
    if (this.adjustRequest) return;
    this.adjustRequest = true;
    await this.$nextTick();
    this.adjustNow();
    this.adjustRequest = false;
  }

  /** Do adjustment synchronously */
  private adjustNow() {
    // Refresh height of recycler
    this.recyclerHeight = this.recycler.$refs.wrapper.clientHeight;
    const extraY = this.recyclerBefore?.clientHeight || 0;

    // Start with the first tick. Walk over all rows counting the
    // y position. When you hit a row with the tick, update y and
    // top values and move to the next tick.
    let tickId = 0;
    let y = extraY;
    let count = 0;

    // We only need to recompute top and visible ticks if count
    // of some tick has changed.
    let needRecomputeTop = false;

    // Check if height changed
    if (this.lastAdjustHeight !== this.height) {
      needRecomputeTop = true;
      this.lastAdjustHeight = this.height;
    }

    for (const row of this.rows) {
      // Check if tick is valid
      if (tickId >= this.ticks.length) break;

      // Check if we hit the next tick
      const tick = this.ticks[tickId];
      if (tick.dayId === row.dayId) {
        tick.y = y;

        // Check if count has changed
        needRecomputeTop ||= tick.count !== count;
        tick.count = count;

        // Move to next tick
        count += row.day.count;
        tickId++;
      }

      y += row.size;
    }

    // Compute visible ticks
    if (needRecomputeTop) {
      this.setTicksTop(count);
      this.computeVisibleTicks();
    }
  }

  /** Mark ticks as visible or invisible */
  private computeVisibleTicks() {
    // Do another pass to figure out which points are visible
    // This is not as bad as it looks, it's actually 12*O(n)
    // because there are only 12 months in a year
    const fontSizePx = parseFloat(
      getComputedStyle(this.$refs.cursorSt as any).fontSize
    );
    const minGap = fontSizePx + (window.innerWidth <= 768 ? 5 : 2);
    let prevShow = -9999;
    for (const [idx, tick] of this.ticks.entries()) {
      // Conservative
      tick.s = false;

      // These aren't for showing
      if (!tick.isMonth) continue;

      // You can't see these anyway, why bother?
      if (tick.top < minGap || tick.top > this.height - minGap) continue;

      // Will overlap with the previous tick. Skip anyway.
      if (tick.top - prevShow < minGap) continue;

      // This is a labelled tick then show it anyway for the sake of best effort
      if (tick.text) {
        prevShow = tick.top;
        tick.s = true;
        continue;
      }

      // Lookahead for next labelled tick
      // If showing this tick would overlap the next one, don't show this one
      let i = idx + 1;
      while (i < this.ticks.length) {
        if (this.ticks[i].text) {
          break;
        }
        i++;
      }
      if (i < this.ticks.length) {
        // A labelled tick was found
        const nextLabelledTick = this.ticks[i];
        if (
          tick.top + minGap > nextLabelledTick.top &&
          nextLabelledTick.top < this.height - minGap
        ) {
          // make sure this will be shown
          continue;
        }
      }

      // Show this tick
      tick.s = true;
      prevShow = tick.top;
    }
  }

  private setTicksTop(total: number) {
    for (const tick of this.ticks) {
      tick.topF = this.height * (tick.count / total);
      tick.top = utils.roundHalf(tick.topF);
    }
  }

  /** Change actual position of the hover cursor */
  private moveHoverCursor(y: number) {
    this.hoverCursorY = y;

    // Get index of previous tick
    let idx = utils.binarySearch(this.ticks, y, "topF");
    if (idx === 0) {
      // use this tick
    } else if (idx >= 1 && idx <= this.ticks.length) {
      idx = idx - 1;
    } else {
      return;
    }

    // DayId of current hover
    const dayId = this.ticks[idx]?.dayId;

    // Special days
    if (dayId === undefined || this.TagDayIDValueSet.has(dayId)) {
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

  /** Binary search and get coords surrounding position */
  private getCoords(y: number, field: "topF" | "y") {
    // Top of first and second ticks
    let top1 = 0,
      top2 = 0,
      y1 = 0,
      y2 = 0;

    // Get index of previous tick
    let idx = utils.binarySearch(this.ticks, y, field);
    if (idx <= 0) {
      top1 = 0;
      top2 = this.ticks[0].topF;
      y1 = 0;
      y2 = this.ticks[0].y;
    } else if (idx >= this.ticks.length) {
      const t = this.ticks[this.ticks.length - 1];
      top1 = t.topF;
      top2 = this.height;
      y1 = t.y;
      y2 = this.recyclerHeight;
    } else {
      const t1 = this.ticks[idx - 1];
      const t2 = this.ticks[idx];
      top1 = t1.topF;
      top2 = t2.topF;
      y1 = t1.y;
      y2 = t2.y;
    }

    return { top1, top2, y1, y2 };
  }

  /** Move to given scroller Y */
  private moveto(y: number) {
    const { top1, top2, y1, y2 } = this.getCoords(y, "topF");
    const yfrac = (y - top1) / (top2 - top1);
    const ry = y1 + (y2 - y1) * yfrac;
    this.recycler.scrollToPosition(ry);

    this.handleScroll();
  }

  /** Handle mouse click */
  private mousedown(event: MouseEvent) {
    this.moveto(event.offsetY);
  }

  /** Handle touch */
  private touchmove(event: any) {
    const rect = (this.$refs.scroller as HTMLElement).getBoundingClientRect();
    const y = event.targetTouches[0].pageY - rect.top;
    event.preventDefault();
    event.stopPropagation();
    this.moveto(y);
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
}
</script>

<style lang="scss" scoped>
@mixin phone {
  @media (max-width: 768px) {
    @content;
  }
}

.scroller {
  overflow-y: clip;
  position: absolute;
  height: 100%;
  width: 36px;
  top: 0;
  right: 0;
  cursor: ns-resize;
  opacity: 0;
  transition: opacity 0.2s ease-in-out;

  // Show on hover or scroll of main window
  &:hover,
  &.scrolling-recycler {
    opacity: 1;
  }

  // Hide ticks on mobile unless hovering
  @include phone {
    // Shift pointer events to hover cursor
    pointer-events: none;
    .cursor.hv {
      pointer-events: all;
    }

    &:not(.scrolling) {
      .cursor.hv {
        left: 5px;
        border: none;
        box-shadow: 0 0 5px -3px #000;
        height: 40px;
        width: 70px;
        border-radius: 20px;
        > .text {
          display: none;
        }
        > .icon {
          display: block;
        }
      }
      > .tick {
        opacity: 0;
      }
    }
    .cursor.st {
      display: none;
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
    top: 0;
    transition: transform 0.2s linear;
    z-index: 1;

    &.dash {
      height: 4px;
      width: 4px;
      border-radius: 50%;
      background-color: var(--color-main-text);
      opacity: 0.15;
      display: block;
      @include phone {
        display: none;
      }
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
        transform: translate(-16px, 6px);
      }
    }
  }
  &.scrolling-recycler-now > .cursor {
    transition: transform 0.1s linear;
  }
  &:hover > .cursor {
    transition: none;
    &.st {
      opacity: 1;
    }
  }
}
</style>