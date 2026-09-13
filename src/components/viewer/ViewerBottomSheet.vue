<!--
  Mobile metadata bottom sheet for the photo viewer.

  Rendered over PhotoSwipe on small screens, showing Metadata for the
  current photo. The parent mounts it while the viewer is open on
  mobile and drives visibility through the `open` prop; the sheet
  requests state changes through the `open` / `close` events.
  PhotoSwipe itself stays non-reactive (see Viewer): the sheet only
  receives a minimal event/pan surface through `photoswipe`.

  Position model: the sheet wraps the full content (never scrolls
  inside) and is moved as a whole with translateY. Detents, top to
  bottom: tail (0, content end docked), head (content start
  fullscreen), peek (content start, 45vh visible), dismissed (full
  height, parked off-screen).

  Gestures: pan anywhere moves the sheet 1:1 with the finger; release
  snaps to a detent or dismisses. Swipe up on the photo opens the
  sheet, tracked through PhotoSwipe pointer events with a touch
  fallback for content refusing the gesture (e.g. video controls).
  Handle taps toggle peek/head; embedded links, buttons and the map
  keep their own behavior. The inline off-screen transform below is
  the initial state; mounted replaces it with the measured offset so
  the first paint never flashes the sheet.
-->
<template>
  <div
    ref="sheet"
    class="viewer-bottom-sheet"
    :class="{ dragging, closing }"
    role="dialog"
    :aria-label="t('memories', 'Info')"
    style="transform: translateY(10000px)"
    @click.capture="onClickCapture"
    @touchstart.passive="onDragStart"
    @touchend="onDragEnd"
    @touchcancel="onDragCancel"
  >
    <div
      class="sheet-handle-area"
      role="button"
      tabindex="0"
      :aria-label="open ? t('memories', 'Collapse details') : t('memories', 'Expand details')"
      @keydown.enter="toggleOpen"
      @keydown.space.prevent="toggleOpen"
    >
      <div class="sheet-handle" />
    </div>

    <div ref="content" class="sheet-content">
      <Metadata ref="metadata" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { PropType } from 'vue';

import Metadata from '@components/Metadata.vue';

import type { IPhoto } from '@typings';

const TAP_SLOP = 10;
const CLOSE_PX = 80;
const DOCK_PX = 40;
const FLING_PX_MS = 0.5;
const CLOSE_ANIM_MS = 280;
const SHEET_SWIPE_UP_PX = 70;

/** PhotoSwipe surface the sheet gestures need */
interface SheetPhotoSwipe {
  on(name: string, fn: (e: any) => void): void;
  off(name: string, fn: (e: any) => void): void;
  element?: HTMLDivElement | null;
  gestures?: { isMultitouch: boolean };
  currSlide?: { pan: { y: number } } | null;
}

export default defineComponent({
  name: 'ViewerBottomSheet',

  components: {
    Metadata,
  },

  props: {
    photo: {
      type: Object as PropType<IPhoto | null>,
      default: null,
    },
    /** Live PhotoSwipe instance driving the viewer */
    photoswipe: {
      type: Object as PropType<SheetPhotoSwipe | null>,
      default: null,
    },
    /** Whether the sheet is open (parent state) */
    open: {
      type: Boolean,
      default: false,
    },
  },

  // Both ask the parent to flip the `open` prop.
  emits: ['close', 'open'],

  data: () => ({
    /** Docked at head/tail (vs peek) */
    expanded: false,
    dragging: false,
    closing: false,
    /** Current translateY of the sheet in px */
    offsetY: 0,
    dragStartY: 0,
    dragBase: 0,
    dragDy: 0,
    lastY: 0,
    lastT: 0,
    velocity: 0,
    startedOnHandle: false,
    suppressClick: false,
    /** Last known detents, to tell parked positions from free panning */
    lastRest: 0,
    lastHead: 0,
    lastFull: 0,
    resizeObserver: null as ResizeObserver | null,
    resizeListener: null as (() => void) | null,
    closeTimer: 0,
    enterTimer: 0,
    /** Element the fallback swipe detector is bound to, if any */
    fallbackEl: null as HTMLElement | null,
    /** Finger position and pan at the current PhotoSwipe gesture start */
    downClientX: 0,
    downClientY: null as number | null,
    downPanY: 0,
    /** Fallback swipe tracking on viewer slides */
    fbStartX: 0,
    fbStartY: 0,
    fbStartT: 0,
    fbTracking: false,
  }),

  watch: {
    photo: {
      handler() {
        // Skip mid-dismiss refreshes; reopening refetches anyway.
        if (this.open && !this.closing) this.refreshMetadata();
      },
    },

    open(isOpen: boolean) {
      this.closing = false;
      window.clearTimeout(this.closeTimer);
      if (isOpen) {
        this.openSheet();
      } else {
        this.offsetY = this.fullHeight();
        this.applyOffset();
      }
    },

    photoswipe() {
      this.setupGestures();
    },
  },

  mounted() {
    this.setupGestures();
    if (this.open) {
      this.openSheet();
    } else {
      this.offsetY = this.fullHeight();
      this.applyOffset();
    }

    this.refs().sheet?.addEventListener('touchmove', this.onDragMove, { passive: false });
    // ResizeObserver batches per frame, so adjust synchronously
    // (pre-paint) to avoid flashing raw growth for a frame.
    this.resizeObserver = new ResizeObserver(() => this.layout());
    this.resizeObserver.observe(this.refs().content);
    this.resizeListener = () => this.layout();
    window.addEventListener('resize', this.resizeListener);
  },

  beforeUnmount() {
    this.teardownGestures();
    this.refs().sheet?.removeEventListener('touchmove', this.onDragMove);
    this.resizeObserver?.disconnect();
    if (this.resizeListener) window.removeEventListener('resize', this.resizeListener);
    window.clearTimeout(this.closeTimer);
    window.clearTimeout(this.enterTimer);
  },

  methods: {
    refs() {
      return this.$refs as {
        sheet: HTMLDivElement;
        content: HTMLDivElement;
        metadata: InstanceType<typeof Metadata>;
      };
    },

    /** Reload metadata for the current photo, then fit the sheet around it. */
    async refreshMetadata() {
      if (!this.photo || this.refs().metadata?.fileid === this.photo.fileid) return;
      await this.refs().metadata?.update(this.photo);
      this.layout();
    },

    // Clear stale content first so enter() measures the skeleton.
    async openSheet() {
      if (this.photo && this.refs().metadata?.fileid !== this.photo.fileid) {
        this.refs().metadata?.invalidateUnless(0);
        await this.$nextTick();
      }
      this.enter();
      this.refreshMetadata();
    },

    /** Full height of the embedded content; the sheet always wraps it all */
    fullHeight(): number {
      return this.refs().sheet?.scrollHeight ?? 0;
    },

    /** Resting translateY showing just the peek area */
    restOffset(): number {
      return Math.max(0, this.fullHeight() - Math.round(window.innerHeight * 0.45));
    },

    /** TranslateY with the head fullscreen (handle at the viewport top) */
    headOffset(): number {
      return Math.max(0, this.fullHeight() - window.innerHeight);
    },

    /** Merged detent list: tail, head, peek */
    spots(): number[] {
      const out: number[] = [];
      for (const s of [0, this.headOffset(), this.restOffset()].sort((a, b) => a - b)) {
        if (!out.length || s - out[out.length - 1] >= DOCK_PX) out.push(s);
      }
      return out;
    },

    /** Remember current detents to recognize parked positions later. */
    syncDetents() {
      this.lastRest = this.restOffset();
      this.lastHead = this.headOffset();
      this.lastFull = this.fullHeight();
    },

    applyOffset() {
      const sheet = this.refs().sheet;
      if (sheet) sheet.style.transform = `translateY(${Math.round(this.offsetY)}px)`;
    },

    /** Run fn with transitions off so the box jumps without animating. */
    freeze(sheet: HTMLElement, fn: () => void) {
      sheet.style.transition = 'none';
      fn();
      this.applyOffset();
      void sheet.offsetHeight;
      sheet.style.transition = '';
    },

    /** Initial slide-up from dismissed, paced to travel distance
     * so long sheets don't fling (fixed duration would). */
    enter() {
      const sheet = this.refs().sheet;
      if (!sheet) return;
      this.freeze(sheet, () => {
        this.offsetY = this.fullHeight();
      });
      const dist = Math.abs(this.restOffset() - this.offsetY);
      const ms = Math.min(600, Math.max(200, Math.round(dist / 1.5)));
      sheet.style.transition = `transform ${ms}ms ease-out`;
      window.clearTimeout(this.enterTimer);
      this.enterTimer = window.setTimeout(() => {
        if (this.refs().sheet) this.refs().sheet.style.transition = '';
      }, ms + 60);
      this.snapPeek();
    },

    // Parked positions follow their detent across content growth; a
    // freely panned sheet keeps its window instead of jumping. Growth
    // freezes the top edge first so the box never chases snapped content.
    layout() {
      if (this.dragging || this.closing) return;
      const sheet = this.refs().sheet;
      if (!sheet) return;

      const full = this.fullHeight();
      const atRest = Math.abs(this.offsetY - this.lastRest) < DOCK_PX;
      const atHead = !atRest && Math.abs(this.offsetY - this.lastHead) < DOCK_PX;
      if (full !== this.lastFull && (atRest || atHead)) {
        this.freeze(sheet, () => {
          this.offsetY += full - this.lastFull;
        });
      }

      if (atRest) this.snapPeek();
      else if (atHead) this.snapHead();
      else {
        this.offsetY = Math.min(full, Math.max(0, this.offsetY));
        this.syncDetents();
        this.applyOffset();
      }
    },

    snapTo(offset: number, expanded: boolean) {
      this.expanded = expanded;
      this.offsetY = offset;
      this.syncDetents();
      this.applyOffset();
    },

    /** Dock at an offset; anything but peek counts as expanded. */
    snapValue(target: number) {
      this.snapTo(target, target !== this.restOffset());
    },

    snapTail() {
      this.snapTo(0, true);
    },

    snapHead() {
      this.snapTo(this.headOffset(), true);
    },

    snapPeek() {
      this.snapTo(this.restOffset(), false);
    },

    /** Handle tap/keyboard: alternate peek and head. */
    toggleOpen() {
      if (this.closing) return;
      if (this.expanded) this.snapPeek();
      else this.snapHead();
    },

    panTarget(e: TouchEvent) {
      return (e.target as HTMLElement).closest('.sheet-handle-area') !== null;
    },

    onDragStart(e: TouchEvent) {
      if (e.touches.length !== 1 || this.closing) return;
      // Embedded interactive content keeps its own gestures (e.g. map pan).
      if ((e.target as HTMLElement).closest('.leaflet-container')) return;
      this.startPan(e.touches[0].clientY, this.panTarget(e));
    },

    onDragMove(e: TouchEvent) {
      if (!this.dragging || e.touches.length !== 1) return;
      // Take over the gesture so nothing behind scrolls or pans.
      if (e.cancelable) e.preventDefault();
      this.movePan(e.touches[0].clientY);
    },

    onDragEnd() {
      if (!this.dragging) return;
      this.dragging = false;
      this.finishPan();
    },

    /** Abort the gesture: snap back to the current dock. */
    onDragCancel() {
      this.dragging = false;
      this.snapTo(this.expanded ? this.headOffset() : this.restOffset(), this.expanded);
    },

    /** Begin a 1:1 pan from the current offset. */
    startPan(clientY: number, onHandle: boolean) {
      this.dragging = true;
      this.dragStartY = clientY;
      this.dragBase = this.offsetY;
      this.dragDy = 0;
      this.lastY = clientY;
      this.lastT = Date.now();
      this.velocity = 0;
      this.startedOnHandle = onHandle;
      this.suppressClick = false;
    },

    movePan(clientY: number) {
      const now = Date.now();
      this.dragDy = clientY - this.dragStartY;
      if (Math.abs(this.dragDy) > TAP_SLOP) {
        this.suppressClick = true;
      }

      const dt = Math.max(1, now - this.lastT);
      this.velocity = 0.7 * this.velocity + (0.3 * (clientY - this.lastY)) / dt;
      this.lastY = clientY;
      this.lastT = now;

      // Follow the finger 1:1 across the whole box, from the tail (0)
      // down to dismissed. The handle may travel above the viewport
      // on long sheets; the sheet never lifts past its own tail.
      this.offsetY = Math.min(this.fullHeight(), Math.max(0, this.dragBase + this.dragDy));
      this.applyOffset();
    },

    // Release: tap toggles, fling steps detents, far-down dismisses,
    // otherwise dock nearby or stay where dropped.
    finishPan() {
      const dy = this.dragDy;
      const vel = this.velocity;
      const rest = this.restOffset();

      // Taps on the handle toggle; taps elsewhere do nothing so
      // embedded links and buttons keep working normally.
      if (Math.abs(dy) < TAP_SLOP) {
        if (this.startedOnHandle) this.toggleOpen();
        return;
      }

      if (vel < -FLING_PX_MS) this.snapNeighbor(-1);
      else if (vel > FLING_PX_MS) this.snapNeighbor(1);
      else if (this.offsetY > rest + CLOSE_PX) this.dismiss();
      else this.snapNearest();
    },

    // Step to the neighboring detent (+1 down, -1 up),
    // falling off the bottom edge into dismiss.
    snapNeighbor(dir: 1 | -1) {
      const spots = this.spots();
      let idx = 0;
      spots.forEach((s, i) => {
        if (s <= this.offsetY + DOCK_PX) idx = i;
      });
      const next = idx + dir;
      if (next >= spots.length) {
        this.dismiss();
        return;
      }
      this.snapValue(spots[Math.max(0, next)]);
    },

    // Dock to a nearby detent, else stay exactly where released.
    snapNearest() {
      const spots = this.spots();
      let near = spots[0];
      for (const s of spots) {
        if (Math.abs(s - this.offsetY) < Math.abs(near - this.offsetY)) near = s;
      }
      if (Math.abs(near - this.offsetY) < DOCK_PX) {
        this.snapValue(near);
      }
    },

    /** Glide off-screen, then ask the parent to close. */
    dismiss() {
      this.closing = true;
      this.offsetY = this.fullHeight();
      this.applyOffset();
      this.closeTimer = window.setTimeout(() => this.$emit('close'), CLOSE_ANIM_MS);
    },

    /** Open the sheet on a sufficient upward swipe (dy<0). */
    maybeOpenSheet(dy: number, dx = 0, dt = 0) {
      if (this.open) return;
      if (dy < -SHEET_SWIPE_UP_PX && Math.abs(dy) > 1.8 * Math.abs(dx) && dt < 800) {
        this.$emit('open');
      }
    },

    /** Wire swipe-up gestures to the PhotoSwipe instance. */
    setupGestures() {
      // Teardown first so repeat calls never double-bind.
      this.teardownGestures();
      const pswp = this.photoswipe;
      if (!pswp) return;

      pswp.on('pointerDown', this.onPsPointerDown);
      pswp.on('pointerUp', this.onPsPointerUp);
      pswp.on('pointerMove', this.onPsPointerMove);
      pswp.on('verticalDrag', this.onPsVerticalDrag);
    },

    teardownGestures() {
      // Listeners are stable method references, so unbinding
      // what was never bound is a safe no-op.
      this.photoswipe?.off('pointerDown', this.onPsPointerDown);
      this.photoswipe?.off('pointerUp', this.onPsPointerUp);
      this.photoswipe?.off('pointerMove', this.onPsPointerMove);
      this.photoswipe?.off('verticalDrag', this.onPsVerticalDrag);
      this.unbindFallback();
    },

    /**
     * Fallback swipe detector for content where verticalDrag does not
     * fire (e.g. video controls refusing the gesture). Scoped to the
     * PhotoSwipe element, so sheet and viewer chrome never reach it.
     */
    bindFallback() {
      const el = this.photoswipe?.element;
      if (!el || this.fallbackEl) return;
      this.fallbackEl = el;
      el.addEventListener('touchstart', this.onFallbackTouchStart, { passive: true });
      el.addEventListener('touchend', this.onFallbackTouchEnd, { passive: true });
    },

    unbindFallback() {
      if (!this.fallbackEl) return;
      this.fallbackEl.removeEventListener('touchstart', this.onFallbackTouchStart);
      this.fallbackEl.removeEventListener('touchend', this.onFallbackTouchEnd);
      this.fallbackEl = null;
    },

    /** Record the gesture origin; a gesture also implies init, so bind the fallback here. */
    onPsPointerDown(e: { originalEvent: PointerEvent }) {
      this.downClientX = e.originalEvent.clientX;
      this.downClientY = e.originalEvent.clientY;
      this.downPanY = this.photoswipe?.currSlide?.pan.y ?? 0;
      // The element only exists after init, which any gesture implies.
      this.bindFallback();
    },

    onPsPointerUp() {
      this.downClientY = null;
    },

    /** Open once the finger traveled far enough upward. */
    onPsPointerMove(e: { originalEvent: PointerEvent }) {
      if (this.downClientY === null || this.open) return;
      if (this.photoswipe?.gestures?.isMultitouch) return;
      this.maybeOpenSheet(e.originalEvent.clientY - this.downClientY, e.originalEvent.clientX - this.downClientX);
    },

    /** Pin upward drags so the photo never follows the finger up (down keeps the native close). */
    onPsVerticalDrag(e: { panY: number; preventDefault(): void }) {
      if (e.panY - this.downPanY >= 0) return;
      e.preventDefault();
    },

    /** Fallback swipe start; buttons keep their own behavior. */
    onFallbackTouchStart(e: TouchEvent) {
      if (e.touches.length !== 1) {
        this.fbTracking = false;
        return;
      }
      if ((e.target as HTMLElement).closest('button')) {
        this.fbTracking = false;
        return;
      }
      this.fbStartX = e.touches[0].clientX;
      this.fbStartY = e.touches[0].clientY;
      this.fbStartT = Date.now();
      this.fbTracking = true;
    },

    /** Fallback swipe end; opens on a quick, mostly-vertical swipe up. */
    onFallbackTouchEnd(e: TouchEvent) {
      if (!this.fbTracking) return;
      this.fbTracking = false;
      if (this.open) return;
      const t = e.changedTouches[0];
      if (!t) return;
      this.maybeOpenSheet(t.clientY - this.fbStartY, t.clientX - this.fbStartX, Date.now() - this.fbStartT);
    },

    onClickCapture(e: Event) {
      // A drag starting on a link would otherwise navigate on release.
      if (this.suppressClick) {
        e.preventDefault();
        e.stopPropagation();
        this.suppressClick = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.viewer-bottom-sheet {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100002;

  // Embedded content: the sheet wraps it all and panning moves
  // the whole sheet, so the inside never scrolls.
  overflow: hidden;
  touch-action: none;
  // Reserve peek space while metadata loads.
  min-height: 45vh;
  min-height: 45dvh;

  background: var(--color-main-background);
  color: var(--color-main-text);
  border-top-left-radius: 16px;
  border-top-right-radius: 16px;
  box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.5);

  &:not(.dragging) {
    transition: transform 0.25s ease-out;
  }
}

.sheet-handle-area {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px 0 6px 0;
  outline-offset: -4px;
}

.sheet-handle {
  width: 40px;
  height: 4px;
  border-radius: 2px;
  background: rgba(128, 128, 128, 0.4);
}

.sheet-content {
  overflow: hidden;
  padding: 0 12px 24px 12px;
  // Floor for height measurement while metadata loads.
  min-height: 40vh;
  min-height: 40dvh;

  // Loading wrapper fills the sheet, giving the absolutely-centered
  // spinner a definite box so it never collapses onto the handle.
  :deep(.loading-icon.fill-block) {
    position: absolute;
    inset: 0;
  }
}
</style>
