<template>
  <div
    class="scroller"
    ref="scroller"
    v-bind:class="{
      'scrolling-recycler-now': scrollingRecyclerNowTimer,
      'scrolling-recycler': scrollingRecyclerTimer,
      'scrolling-now': scrollingNowTimer,
      scrolling: scrollingTimer,
    }"
    @mousemove.passive="mousemove"
    @mouseleave.passive="mouseleave"
    @mousedown.passive="mousedown"
    @mouseup.passive="interactend"
    @touchmove.prevent="touchmove"
    @touchstart.passive="interactstart"
    @touchend.passive="interactend"
    @touchcancel.passive="interactend"
  >
    <span class="cursor st" ref="cursorSt" :style="{ transform: `translateY(${cursorY}px)` }"> </span>

    <span
      ref="hoverCursor"
      class="cursor hv"
      :style="{ transform: hoverCursorTransform }"
      @touchmove.prevent="touchmove"
      @touchstart.passive="interactstart"
      @touchend.passive="interactend"
      @touchcancel.passive="interactend"
    >
      <div class="text">{{ hoverCursorText }}</div>
      <div class="icon">
        <ScrollUpIcon v-once :size="22" />
        <ScrollDownIcon v-once :size="22" />
      </div>
    </span>

    <div class="ticks-container top-left fill-block">
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
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';
import { IRow, IRowType, ITick } from '../types';

import ScrollUpIcon from 'vue-material-design-icons/MenuUp.vue';
import ScrollDownIcon from 'vue-material-design-icons/MenuDown.vue';

import * as utils from '../services/utils';

const SNAP_OFFSET = -5; // Pixels to snap at
const SNAP_MIN_ROWS = 1000; // Minimum rows to snap at
const MOBILE_CURSOR_HH = 22; // Half height of the mobile cursor (CSS)

export default defineComponent({
  name: 'ScrollerManager',
  components: {
    ScrollUpIcon,
    ScrollDownIcon,
  },

  props: {
    /** Rows from Timeline */
    rows: {
      type: Array as PropType<IRow[]>,
      required: true,
    },
    /** Total height */
    fullHeight: {
      type: Number,
      required: true,
    },
    /** Actual recycler component */
    recycler: {
      type: Object as PropType<VueRecyclerType>,
      required: false,
    },
    /** Recycler before slot component */
    recyclerBefore: {
      type: HTMLDivElement,
      required: false,
    },
  },

  emits: {
    interactend: () => true,
  },

  data: () => ({
    /** Last known height at adjustment */
    lastAdjustHeight: 0,
    /** Height of the entire photo view */
    recyclerHeight: 100,
    /** Height of the dynamic top matter */
    dynTopMatterHeight: 0,
    /** Space to leave at the top (for the hover cursor) */
    topPadding: 0,
    /** Rect of scroller */
    scrollerRect: null as DOMRect | null,
    /** Computed ticks */
    ticks: [] as ITick[],
    /** Computed cursor top */
    cursorY: 0,
    /** Hover cursor top */
    hoverCursorY: -5,
    /** Hover cursor text */
    hoverCursorText: '',
    /** Scrolling using the scroller */
    scrollingTimer: 0,
    /** Scrolling now using the scroller */
    scrollingNowTimer: 0,
    /** Scrolling recycler */
    scrollingRecyclerTimer: 0,
    /** Scrolling recycler now */
    scrollingRecyclerNowTimer: 0,
    /** Recycler scrolling throttle */
    scrollingRecyclerUpdateTimer: 0,
    /** View size reflow timer */
    reflowRequest: false,
    /** Tick adjust timer */
    adjustRequest: false,
    /** Scroller is being moved with interaction */
    interacting: false,
    /** Last known scroll position of the recycler */
    lastKnownRecyclerScroll: 0,
    /** Track the last requested y position when interacting */
    lastRequestedRecyclerY: 0,
  }),

  computed: {
    refs() {
      return this.$refs as {
        scroller?: HTMLDivElement;
        cursorSt?: HTMLSpanElement;
        hoverCursor?: HTMLSpanElement;
      };
    },

    /** Get the visible ticks */
    visibleTicks(): ITick[] {
      let key = 9999999900;
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
    },

    /** Height of usable area */
    height(): number {
      return this.fullHeight - this.topPadding;
    },

    /** Position of hover cursor */
    hoverCursorTransform(): string {
      const mob = utils.isMobile();
      const min = this.topPadding + (mob ? 2 : 0); // padding for curvature
      const max = this.fullHeight - (mob ? 6 : 0); // padding for shadow
      const val = this.hoverCursorY;
      const clamp = Math.max(min, Math.min(max, val)); // clamp(min, val, max)
      return `translateY(calc(${clamp}px - 100%))`;
    },
  },

  methods: {
    /** Reset state */
    reset() {
      this.ticks = [];
      this.cursorY = 0;
      this.hoverCursorY = -5;
      this.hoverCursorText = '';
      this.reflowRequest = false;

      // Clear all timers
      clearTimeout(this.scrollingTimer);
      clearTimeout(this.scrollingNowTimer);
      clearTimeout(this.scrollingRecyclerTimer);
      clearTimeout(this.scrollingRecyclerNowTimer);
      clearTimeout(this.scrollingRecyclerUpdateTimer);
      this.scrollingTimer = 0;
      this.scrollingNowTimer = 0;
      this.scrollingRecyclerTimer = 0;
      this.scrollingRecyclerNowTimer = 0;
      this.scrollingRecyclerUpdateTimer = 0;
    },

    /** Recycler scroll event, must be called by timeline */
    recyclerScrolled(event: Event | null) {
      // This isn't a renewing timer, it's a scheduled task
      if (this.scrollingRecyclerUpdateTimer) return;
      this.scrollingRecyclerUpdateTimer = window.setTimeout(() => {
        this.scrollingRecyclerUpdateTimer = 0;
        this.updateFromRecyclerScroll();
      }, 100);

      // Update that we're scrolling with the recycler
      utils.setRenewingTimeout(this, 'scrollingRecyclerNowTimer', null, 200);
      utils.setRenewingTimeout(this, 'scrollingRecyclerTimer', null, 1500);
    },

    /** Update cursor position from recycler scroll position */
    updateFromRecyclerScroll() {
      // Ignore if dragging the scroller
      if (this.interacting) return;

      // Get the scroll position
      const scroll = this.recycler?.$el?.scrollTop || 0;

      // Emit scroll event
      utils.bus.emit('memories.recycler.scroll', {
        current: scroll,
        previous: this.lastKnownRecyclerScroll,
        dynTopMatterVisible: scroll < this.dynTopMatterHeight,
      });
      this.lastKnownRecyclerScroll = scroll;

      // Get cursor px position
      const { top1, top2, y1, y2 } = this.getCoords(scroll, 'y');
      const topfrac = (scroll - y1) / (y2 - y1);
      const rtop = top1 + (top2 - top1) * (topfrac || 0);

      // Always move static cursor to right position
      this.cursorY = rtop;

      // Move hover cursor to same position unless hovering
      // Regardless, we need this call because the internal mapping might have changed
      if (!utils.isMobile() && this.refs.scroller?.matches(':hover')) {
        this.moveHoverCursor(this.hoverCursorY);
      } else {
        this.moveHoverCursor(rtop);
      }
    },

    /** Re-create tick data in the next frame */
    async reflow() {
      if (this.reflowRequest) return;
      this.reflowRequest = true;
      await this.$nextTick();
      this.reflowNow();
      this.reflowRequest = false;
    },

    /** Re-create tick data */
    reflowNow() {
      // Ignore if not initialized
      if (!this.recycler?.$refs.wrapper) return;

      // Refresh height of recycler
      this.recyclerHeight = this.recycler?.$refs.wrapper.clientHeight ?? 0;

      // Recreate ticks data
      this.recreate();

      // Adjust top
      this.adjustNow();
    },

    /** Recreate from scratch */
    recreate() {
      // Clear and override any adjust timer
      this.ticks = [];
      this.lastAdjustHeight = 0;

      // Ticks
      let prevYear = 9999;
      let prevMonth = 0;

      // Get a new tick
      const getTick = (dayId: number, isMonth = false, text?: string | number): ITick => ({
        dayId,
        isMonth,
        text,
        y: 0,
        count: 0,
        topF: 0,
        top: 0,
        s: false,
      });

      // Iterate over rows
      for (const row of this.rows) {
        if (row.type === IRowType.HEAD) {
          // Make date string
          const dateTaken = utils.dayIdToDate(row.dayId);

          // Create tick
          const dtYear = dateTaken.getUTCFullYear();
          const dtMonth = dateTaken.getUTCMonth();
          const isMonth = dtMonth !== prevMonth || dtYear !== prevYear;
          const text = dtYear === prevYear ? undefined : dtYear;
          this.ticks.push(getTick(row.dayId, isMonth, text));

          prevMonth = dtMonth;
          prevYear = dtYear;
        }
      }
    },

    /**
     * Update tick positions without truncating the list
     * This is much cheaper than reflowing the whole thing
     */
    async adjust() {
      if (this.adjustRequest) return;
      this.adjustRequest = true;
      await this.$nextTick();
      this.adjustNow();
      this.adjustRequest = false;
    },

    /** Do adjustment synchronously */
    adjustNow() {
      // Refresh height of recycler
      this.recyclerHeight = this.recycler?.$refs.wrapper.clientHeight ?? 0;
      this.dynTopMatterHeight = this.recyclerBefore?.clientHeight ?? 0;

      // Exclude hover cursor height
      const hoverCursor = this.refs.hoverCursor;
      this.topPadding = hoverCursor?.offsetHeight ?? 0;

      // Add extra padding for any top elements (top matter, mobile header)
      document.querySelectorAll('.timeline-scroller-gap').forEach((el) => {
        this.topPadding += el.clientHeight + 1;
      });

      // Start with the first tick. Walk over all rows counting the
      // y position. When you hit a row with the tick, update y and
      // top values and move to the next tick.
      let tickId = 0;
      let y = this.dynTopMatterHeight;
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
    },

    /** Mark ticks as visible or invisible */
    computeVisibleTicks() {
      // Kind of unrelated here, but refresh rect
      this.scrollerRect = this.refs.scroller!.getBoundingClientRect();

      // Do another pass to figure out which points are visible
      // This is not as bad as it looks, it's actually 12*O(n)
      // because there are only 12 months in a year
      const fontSizePx = parseFloat(getComputedStyle(this.refs.cursorSt!).fontSize);
      const minGap = fontSizePx + (_m.window.innerWidth <= 768 ? 5 : 2);
      let prevShow = -9999;
      for (const [idx, tick] of this.ticks.entries()) {
        // Conservative
        tick.s = false;

        // These aren't for showing
        if (!tick.isMonth) continue;

        // You can't see these anyway, why bother?
        const minTop = this.topPadding + minGap;
        const maxTop = this.fullHeight - minGap;
        if (tick.top < minTop || tick.top > maxTop) continue;

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
          if (tick.top + minGap > nextLabelledTick.top && nextLabelledTick.top < this.height - minGap) {
            // make sure this will be shown
            continue;
          }
        }

        // Show this tick
        tick.s = true;
        prevShow = tick.top;
      }
    },

    setTicksTop(total: number) {
      // On mobile, move the ticks up by half the height of the cursor
      // so that the cursor is centered on the tick instead (on desktop, it's at the bottom)
      const displayPadding = utils.isMobile() ? -MOBILE_CURSOR_HH : 0;

      // Set topF (float) and top (rounded) values
      for (const tick of this.ticks) {
        tick.topF = this.topPadding + this.height * (tick.count / total);
        tick.top = utils.roundHalf(tick.topF) + displayPadding;
      }
    },

    /** Change actual position of the hover cursor */
    moveHoverCursor(y: number) {
      this.hoverCursorY = y;

      // Get index of previous tick
      let idx = utils.binarySearch(this.ticks, y, 'topF');
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
      if (dayId === undefined) {
        this.hoverCursorText = '';
        return;
      }

      const date = utils.dayIdToDate(dayId);
      this.hoverCursorText = utils.getShortDateStr(date) ?? '';
    },

    /** Handle mouse hover */
    mousemove(event: MouseEvent) {
      if (event.buttons) {
        this.mousedown(event);
      }
      this.moveHoverCursor(event.offsetY);
    },

    /** Handle mouse leave */
    mouseleave(event: MouseEvent) {
      this.interactend();
      this.moveHoverCursor(this.cursorY);
    },

    /** Binary search and get coords surrounding position */
    getCoords(y: number, field: 'topF' | 'y') {
      // If no ticks are available, return a linear interpolation
      if (!this.ticks.length) {
        // Include the dynamic top matter height here because
        // this will likely be used when there are zero rows
        return {
          top1: this.topPadding,
          top2: this.fullHeight,
          y1: 0,
          y2: this.recyclerHeight + this.dynTopMatterHeight,
        };
      }

      // Get index of previous tick
      const idx = utils.binarySearch(this.ticks, y, field);

      // Position is before the first tick; choose first
      if (idx <= 0) {
        const tick = this.ticks[0];
        return {
          top1: this.topPadding,
          top2: tick.topF,
          y1: 0,
          y2: tick.y,
        };
      }

      // Position is after the last tick; choose last
      if (idx >= this.ticks.length) {
        const tick = this.ticks[this.ticks.length - 1];
        return {
          top1: tick.topF,
          top2: this.fullHeight,
          y1: tick.y,
          y2: this.recyclerHeight,
        };
      }

      // Somewhere in the middle
      const tick1 = this.ticks[idx - 1];
      const tick2 = this.ticks[idx];
      return {
        top1: tick1.topF,
        top2: tick2.topF,
        y1: tick1.y,
        y2: tick2.y,
      };
    },

    /** Move to given scroller Y */
    moveto(y: number, snap: boolean) {
      // Move cursor immediately to prevent jank
      this.cursorY = y;
      this.hoverCursorY = y;

      const { top1, top2, y1, y2 } = this.getCoords(y, 'topF');
      const yfrac = (y - top1) / (top2 - top1);
      const ry = y1 + (y2 - y1) * (yfrac || 0);
      const targetY = snap ? y1 + SNAP_OFFSET : ry;

      if (this.lastRequestedRecyclerY !== targetY) {
        this.lastRequestedRecyclerY = targetY;
        this.recycler?.scrollToPosition(targetY);
      }

      this.handleScroll();
    },

    /** Handle mouse click */
    mousedown(event: MouseEvent) {
      this.interactstart(); // end called on mouseup
      this.moveto(event.offsetY, false);
    },

    /** Handle touch */
    touchmove(event: TouchEvent) {
      if (!this.scrollerRect) return;
      let y = event.targetTouches[0].pageY - this.scrollerRect.top;
      y = Math.max(this.topPadding, y + MOBILE_CURSOR_HH); // middle of touch finger

      // Snap to nearest tick if there are a lot of rows
      const snap = this.rows.length > SNAP_MIN_ROWS;
      this.moveto(y, snap);
    },

    interactstart() {
      this.interacting = true;
    },

    interactend() {
      this.interacting = false;
      this.recyclerScrolled(null); // make sure final position is correct
      this.$emit('interactend'); // tell recycler to load stuff
    },

    /** Update scroller is being used to scroll recycler */
    handleScroll() {
      utils.setRenewingTimeout(this, 'scrollingNowTimer', null, 200);
      utils.setRenewingTimeout(this, 'scrollingTimer', null, 1500);
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

.scroller {
  contain: layout style;
  overflow-y: clip;
  position: absolute;
  height: 100%;
  width: 36px;
  top: 0;
  right: 0;
  z-index: 100; // below top-matter and top-bar
  cursor: ns-resize;
  opacity: 0;
  transition:
    opacity 0.2s ease-in-out,
    visibility 0.2s ease-in-out;

  // Show on hover or scroll of main window
  &:hover,
  &.scrolling-recycler {
    opacity: 1;
    visibility: visible;
  }

  // On phone, there is no point of hover, so just hide it when not scrolling
  @include phone {
    visibility: hidden;
  }

  > .ticks-container {
    pointer-events: none;
  }

  > .ticks-container > .tick {
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
      padding: 1px 5px;
      border-bottom: 2px solid var(--color-primary);
      border-radius: 2px;
      width: auto;
      white-space: nowrap;
      z-index: 100;
      font-size: 0.95em;
      font-weight: 600;
      height: calc(1.2em + 10px);

      > .icon {
        display: none;
        color: var(--color-main-text);
        opacity: 0.75;

        :deep > .menu-up-icon {
          transform: translate(-3px, 4px);
        }
        :deep > .menu-down-icon {
          transform: translate(-3px, -6px);
        }
      }
    }
  }
  &.scrolling-recycler-now:not(.scrolling-now) > .cursor {
    transition: transform 0.1s linear;
  }
  &:hover > .cursor {
    transition: none !important;
    &.st {
      opacity: 1;
    }
  }

  // Hide ticks on mobile unless hovering
  @include phone {
    // Shift pointer events to hover cursor
    pointer-events: none;
    .cursor.hv {
      pointer-events: all;
    }

    > .ticks-container > .tick {
      right: 40px;
    }
    &:not(.scrolling) {
      > .ticks-container {
        display: none;
      }
    }

    .cursor.hv {
      left: 6px;
      border: none;
      box-shadow: -1px 2px 11px -5px #000;
      height: 44px;
      width: 44px;
      border-radius: 22px;
      > .text {
        display: none;
      }
      > .icon {
        display: block;
      }
    }

    .cursor.st {
      display: none;
    }
  }
}
</style>
