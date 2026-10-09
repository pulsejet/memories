<template>
  <div
    class="scroller"
    ref="scroller"
    v-bind:class="{
      'scrolling-recycler-now': scrollingRecyclerNowTimer.pending,
      'scrolling-recycler': scrollingRecyclerTimer.pending,
      'scrolling-now': scrollingNowTimer.pending,
      scrolling: scrollingTimer.pending,
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
        :style="{ transform: `translateY(calc(${tick.top}px - 50%))` }"
      >
        <span v-if="tick.text">{{ tick.text }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, useTemplateRef } from 'vue';

import { windowDims } from '@services/viewport';
import { RenewingTimeout } from '@services/utils/renewing-timeout';
import * as utils from '@services/utils/common';
import * as lens from '@services/lens';

import type { IRow, ITick } from '@typings';

import ScrollUpIcon from 'vue-material-design-icons/MenuUp.vue';
import ScrollDownIcon from 'vue-material-design-icons/MenuDown.vue';

const SNAP_OFFSET = -5; // Pixels to snap at
const SNAP_MIN_ROWS = 1000; // Minimum rows to snap at
const MOBILE_CURSOR_HH = 22; // Half height of the mobile cursor (CSS)

const VIBRATE_MS = 10; // Duration of tick haptic on mobile
const VIBRATE_THROTTLE_MS = 40; // Minimum gap between haptic vibrations

const props = defineProps<{
  /** Rows from Timeline */
  rows: IRow[];
  /** Total height */
  fullHeight: number;
  /** Actual recycler component */
  recycler?: VueRecyclerType | null;
  /** Recycler before slot component */
  recyclerBefore?: HTMLDivElement | null;
}>();

const emit = defineEmits<{
  interactend: [];
  scroll: [event: { current: number; previous: number }];
}>();

const scroller = useTemplateRef<HTMLDivElement>('scroller');
const cursorSt = useTemplateRef<HTMLSpanElement>('cursorSt');
const hoverCursor = useTemplateRef<HTMLSpanElement>('hoverCursor');

/** Last known height at adjustment */
const lastAdjustHeight = ref(0);
/** Height of the entire photo view */
const recyclerHeight = ref(100);
/** Height of the dynamic top matter */
const dynTopMatterHeight = ref(0);
/** Space to leave at the top (for the hover cursor) */
const topPadding = ref(0);
/** Rect of scroller */
const scrollerRect = ref<DOMRect | null>(null);
/** Computed ticks */
const ticks = ref([] as ITick[]);
/** Computed cursor top */
const cursorY = ref(0);
/** Hover cursor top */
const hoverCursorY = ref(-5);
/** Hover cursor text */
const hoverCursorText = ref('');
/** Scrolling using the scroller */
const scrollingTimer = new RenewingTimeout();
/** Scrolling now using the scroller */
const scrollingNowTimer = new RenewingTimeout();
/** Scrolling recycler */
const scrollingRecyclerTimer = new RenewingTimeout();
/** Scrolling recycler now */
const scrollingRecyclerNowTimer = new RenewingTimeout();
/** Recycler scrolling throttle */
const scrollingRecyclerUpdateTimer = ref(0);
/** Recycler scrolling animation frame */
const scrollingRecyclerUpdateFrame = ref(0);
/** View size reflow timer */
const reflowRequest = ref(false);
/** Tick adjust timer */
const adjustRequest = ref(false);
/** Scroller is being moved with interaction */
const interacting = ref(false);
/** Last known scroll position of the recycler */
const lastKnownRecyclerScroll = ref(0);
/** Track the last requested y position when interacting */
const lastRequestedRecyclerY = ref(NaN);
/** Last time a haptic tick was emitted while dragging the handle */
const lastVibrateTime = ref(0);

/** Get the visible ticks */
const visibleTicks = computed(() => {
  let key = 9999999900;
  return ticks.value
    .filter((tick) => tick.s)
    .map((tick) => {
      if (tick.text) {
        tick.key = key = tick.dayId * 100;
      } else {
        tick.key = ++key; // days are sorted descending
      }
      return tick;
    });
});

/** Height of usable area */
const height = computed(() => props.fullHeight - topPadding.value);

/** Position of hover cursor */
const hoverCursorTransform = computed(() => {
  const mob = windowDims.isMobile;
  const min = topPadding.value + (mob ? 2 : 0); // padding for curvature
  const max = props.fullHeight - (mob ? 6 : 0); // padding for shadow
  const val = hoverCursorY.value;
  const clamp = Math.max(min, Math.min(max, val)); // clamp(min, val, max)
  return `translateY(calc(${clamp}px - 100%))`;
});

/** Reset state */
function reset() {
  ticks.value = [];
  cursorY.value = 0;
  hoverCursorY.value = -5;
  hoverCursorText.value = '';
  reflowRequest.value = false;
  adjustRequest.value = false;
  interacting.value = false;
  lastKnownRecyclerScroll.value = 0;
  lastRequestedRecyclerY.value = NaN;

  // Clear all timers
  scrollingTimer.clear();
  scrollingNowTimer.clear();
  scrollingRecyclerTimer.clear();
  scrollingRecyclerNowTimer.clear();
  clearTimeout(scrollingRecyclerUpdateTimer.value);
  scrollingRecyclerUpdateTimer.value = 0;
  cancelAnimationFrame(scrollingRecyclerUpdateFrame.value);
  scrollingRecyclerUpdateFrame.value = 0;
}

/** Query height of the recycler */
function recyclerHeightDOM(): number {
  return props.recycler?.$el?.scrollHeight ?? 0;
}

/** Recycler scroll event, must be called by timeline */
function recyclerScrolled(event: Event | null) {
  // This isn't a renewing timer, it's a scheduled task
  if (scrollingRecyclerUpdateTimer.value || scrollingRecyclerUpdateFrame.value) return;
  scrollingRecyclerUpdateTimer.value = window.setTimeout(() => {
    scrollingRecyclerUpdateTimer.value = 0;
    scrollingRecyclerUpdateFrame.value = window.requestAnimationFrame(() => {
      scrollingRecyclerUpdateFrame.value = 0;
      updateFromRecyclerScroll();
    });
  }, 100);

  // Update that we're scrolling with the recycler
  scrollingRecyclerNowTimer.set(null, 200);
  scrollingRecyclerTimer.set(null, 1500);
}

/** Update cursor position from recycler scroll position */
function updateFromRecyclerScroll() {
  // Ignore if dragging the scroller
  if (interacting.value) return;

  // Get the scroll position
  const scroll = props.recycler?.$el?.scrollTop ?? 0;

  // Emit scroll event
  const event = {
    current: scroll,
    previous: lastKnownRecyclerScroll.value,
    dynTopMatterVisible: scroll < dynTopMatterHeight.value,
  };
  utils.bus.emit('memories.recycler.scroll', event);
  emit('scroll', event);
  lastKnownRecyclerScroll.value = scroll;

  // Get cursor px position
  const { top1, top2, y1, y2 } = getCoords(scroll, 'y');
  const topfrac = y2 === y1 ? 0 : (scroll - y1) / (y2 - y1);
  const rtop = top1 + (top2 - top1) * (topfrac || 0);

  // Always move static cursor to right position
  cursorY.value = rtop;

  // Move hover cursor to same position unless hovering
  // Regardless, we need this call because the internal mapping might have changed
  if (!windowDims.isMobile && scroller.value?.matches(':hover')) {
    moveHoverCursor(hoverCursorY.value);
  } else {
    moveHoverCursor(rtop);
  }
}

/** Re-create tick data in the next frame */
async function reflow() {
  if (reflowRequest.value) return;
  reflowRequest.value = true;
  await nextTick();
  reflowNow();
  reflowRequest.value = false;
}

/** Re-create tick data */
function reflowNow() {
  // Ignore if not initialized
  if (!props.recycler?.$el) return;

  // Refresh height of recycler
  recyclerHeight.value = recyclerHeightDOM();

  // Recreate ticks data
  recreate();

  // Adjust top
  adjustNow();

  // Update cursors
  updateFromRecyclerScroll();
}

/** Recreate from scratch */
function recreate() {
  // Clear and override any adjust timer
  ticks.value = [];
  lastAdjustHeight.value = 0;

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
  for (const row of props.rows) {
    if (row.type === 0) {
      // Make date string
      const dateTaken = utils.dayIdToDate(row.dayId);

      // Create tick
      const dtYear = dateTaken.getUTCFullYear();
      const dtMonth = dateTaken.getUTCMonth();
      const isMonth = dtMonth !== prevMonth || dtYear !== prevYear;
      const text = dtYear === prevYear ? undefined : dtYear;
      ticks.value.push(getTick(row.dayId, isMonth, text));

      prevMonth = dtMonth;
      prevYear = dtYear;
    }
  }
}

/**
 * Update tick positions without truncating the list
 * This is much cheaper than reflowing the whole thing
 */
async function adjust() {
  if (adjustRequest.value) return;
  adjustRequest.value = true;
  await nextTick();
  adjustNow();
  adjustRequest.value = false;
}

/** Do adjustment synchronously */
function adjustNow() {
  // Refresh height of recycler
  recyclerHeight.value = recyclerHeightDOM();
  dynTopMatterHeight.value = props.recyclerBefore?.clientHeight ?? 0;

  // Exclude hover cursor height
  topPadding.value = hoverCursor.value?.offsetHeight ?? 0;

  // Add extra padding for any top elements (top matter, mobile header)
  document.querySelectorAll('.timeline-scroller-gap').forEach((el) => {
    topPadding.value += el.clientHeight + 1;
  });

  // Start with the first tick. Walk over all rows counting the
  // y position. When you hit a row with the tick, update y and
  // top values and move to the next tick.
  let tickId = 0;
  let y = dynTopMatterHeight.value;
  let count = 0;

  // We only need to recompute top and visible ticks if count
  // of some tick has changed.
  let needRecomputeTop = false;

  // Check if height changed
  if (lastAdjustHeight.value !== height.value) {
    needRecomputeTop = true;
    lastAdjustHeight.value = height.value;
  }

  for (const row of props.rows) {
    // Check if tick is valid
    if (tickId >= ticks.value.length) break;

    // Check if we hit the next tick
    const tick = ticks.value[tickId];
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
    setTicksTop(count);
    computeVisibleTicks();
  }
}

/** Mark ticks as visible or invisible */
function computeVisibleTicks() {
  // Kind of unrelated here, but refresh rect
  scrollerRect.value = scroller.value!.getBoundingClientRect();

  // Do another pass to figure out which points are visible
  // This is not as bad as it looks, it's actually 12*O(n)
  // because there are only 12 months in a year
  const fontSizePx = parseFloat(getComputedStyle(cursorSt.value!).fontSize);
  const minGap = fontSizePx + (windowDims.isMobile ? 5 : 2);
  let prevShow = -9999;
  for (const [idx, tick] of ticks.value.entries()) {
    // Conservative
    tick.s = false;

    // These aren't for showing
    if (!tick.isMonth) continue;

    // You can't see these anyway, why bother?
    const minTop = topPadding.value + minGap;
    const maxTop = props.fullHeight - minGap;
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
    while (i < ticks.value.length) {
      if (ticks.value[i].text) {
        break;
      }
      i++;
    }
    if (i < ticks.value.length) {
      // A labelled tick was found
      const nextLabelledTick = ticks.value[i];
      if (tick.top + minGap > nextLabelledTick.top && nextLabelledTick.top < height.value - minGap) {
        // make sure this will be shown
        continue;
      }
    }

    // Show this tick
    tick.s = true;
    prevShow = tick.top;
  }
}

function setTicksTop(total: number) {
  // On mobile, move the ticks up by half the height of the cursor
  // so that the cursor is centered on the tick instead (on desktop, it's at the bottom)
  const displayPadding = windowDims.isMobile ? -MOBILE_CURSOR_HH : 0;

  // Set topF (float) and top (rounded) values
  for (const tick of ticks.value) {
    tick.topF = topPadding.value + height.value * (tick.count / total);
    tick.top = utils.roundHalf(tick.topF) + displayPadding;
  }
}

/** Change actual position of the hover cursor */
function moveHoverCursor(y: number) {
  hoverCursorY.value = y;

  // Get index of previous tick
  let idx = utils.binarySearch(ticks.value, y, 'topF');
  if (idx === 0) {
    // use this tick
  } else if (idx >= 1 && idx <= ticks.value.length) {
    idx = idx - 1;
  } else {
    return;
  }

  // DayId of current hover
  const dayId = ticks.value[idx]?.dayId;

  // Special days
  if (dayId === undefined) {
    hoverCursorText.value = '';
    return;
  } else if (dayId === lens.TOP_RESULTS_DAYID) {
    hoverCursorText.value = lens.TOP_RESULTS_TEXT;
    return;
  }

  const date = utils.dayIdToDate(dayId);
  hoverCursorText.value = utils.getShortDateStr(date) ?? '';
}

/** Handle mouse hover */
function mousemove(event: MouseEvent) {
  if (event.buttons) {
    mousedown(event);
  }
  moveHoverCursor(event.offsetY);
}

/** Handle mouse leave */
function mouseleave(event: MouseEvent) {
  interactend();
  moveHoverCursor(cursorY.value);
}

/** Binary search and get coords surrounding position */
function getCoords(y: number, field: 'topF' | 'y') {
  // If no ticks are available, return a linear interpolation
  if (!ticks.value.length) {
    // Include the dynamic top matter height here because
    // this will likely be used when there are zero rows
    return {
      top1: topPadding.value,
      top2: props.fullHeight,
      y1: 0,
      y2: recyclerHeight.value + dynTopMatterHeight.value,
    };
  }

  // Get index of previous tick
  const idx = utils.binarySearch(ticks.value, y, field);

  // Position is before the first tick; choose first
  if (idx <= 0) {
    const tick = ticks.value[0];
    return {
      top1: topPadding.value,
      top2: tick.topF,
      y1: 0,
      y2: tick.y,
    };
  }

  // Position is after the last tick; choose last
  if (idx >= ticks.value.length) {
    const tick = ticks.value.at(-1)!;
    return {
      top1: tick.topF,
      top2: props.fullHeight,
      y1: tick.y,
      y2: recyclerHeight.value,
    };
  }

  // Somewhere in the middle
  const tick1 = ticks.value[idx - 1];
  const tick2 = ticks.value[idx];
  return {
    top1: tick1.topF,
    top2: tick2.topF,
    y1: tick1.y,
    y2: tick2.y,
  };
}

/** Move to given scroller Y */
function moveto(y: number, snap: boolean) {
  // Move cursor immediately to prevent jank
  cursorY.value = y;
  hoverCursorY.value = y;

  const { top1, top2, y1, y2 } = getCoords(y, 'topF');
  const yfrac = top2 === top1 ? 0 : (y - top1) / (top2 - top1);
  const ry = y1 + (y2 - y1) * (yfrac || 0);
  const targetY = snap ? y1 + SNAP_OFFSET : ry;

  if (lastRequestedRecyclerY.value !== targetY) {
    lastRequestedRecyclerY.value = targetY;
    props.recycler?.scrollToPosition(targetY);
    vibrateTick();
  }

  handleScroll();
}

/** Handle mouse click */
function mousedown(event: MouseEvent) {
  interactstart(); // end called on mouseup
  moveto(event.offsetY, false);
}

/** Handle touch */
function touchmove(event: TouchEvent) {
  if (!scrollerRect.value) return;
  let y = event.targetTouches[0].pageY - scrollerRect.value.top;
  y = Math.max(topPadding.value, y + MOBILE_CURSOR_HH); // middle of touch finger

  // Snap to nearest tick if there are a lot of rows
  const snap = props.rows.length > SNAP_MIN_ROWS;
  moveto(y, snap);
}

function interactstart() {
  interacting.value = true;
}

function interactend() {
  interacting.value = false;
  recyclerScrolled(null); // make sure final position is correct
  emit('interactend'); // tell recycler to load stuff
  props.recycler?.$el.focus(); // give focus back to recycler
}

/** Update scroller is being used to scroll recycler */
function handleScroll() {
  scrollingNowTimer.set(null, 200);
  scrollingTimer.set(null, 1500);
}

/** Tiny haptic tick while dragging the handle on mobile */
function vibrateTick() {
  if (!windowDims.isMobile) return;
  if (!('vibrate' in navigator)) return;
  const now = performance.now();
  if (now - lastVibrateTime.value < VIBRATE_THROTTLE_MS) return;
  lastVibrateTime.value = now;
  try {
    navigator.vibrate(VIBRATE_MS);
  } catch {
    // ignore
  }
}

defineExpose({
  reset,
  reflow,
  adjust,
  recyclerScrolled,
  interacting,
  scrollingRecyclerNowTimer,
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

    &:not(:has(span)) {
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

        > :deep(.menu-up-icon) {
          transform: translate(-3px, 4px);
        }
        > :deep(.menu-down-icon) {
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
