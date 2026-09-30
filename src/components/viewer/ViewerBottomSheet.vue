<!--
  Mobile metadata bottom sheet for the photo viewer.

  Rendered over PhotoSwipe on small screens while open, showing
  Metadata for the current photo; unmounted when closed, so nothing
  of it remains on screen. Swipe-up detection lives in
  ViewerSheetGestures; this component only pans, snaps and dismisses.
  PhotoSwipe itself is never touched here (see Viewer).

  Position model: the sheet wraps the full content (never scrolls
  inside) and is anchored by its top edge. Detents, top to
  bottom: tail (content end docked), head (content start
  fullscreen), peek (content start, min(45vh, 340px) visible),
  dismissed (parked off-screen during close). All motion runs
  through transform deltas over the exact top, so content growth
  extending downward never disturbs an animation.

  Gestures: pan anywhere moves the sheet 1:1 with the finger; release
  snaps to a detent or dismisses. Handle taps toggle peek/head;
  embedded links, buttons and the map keep their own behavior.
-->
<template>
  <div
    ref="sheet"
    class="viewer-bottom-sheet"
    :class="{ dragging, closing }"
    role="dialog"
    :aria-label="t('memories', 'Info')"
    style="top: 10000px"
    @click.capture="onClickCapture"
    @touchstart.passive="onDragStart"
    @touchend="onDragEnd"
    @touchcancel="onDragCancel"
  >
    <div
      class="sheet-handle-area"
      role="button"
      tabindex="0"
      :aria-label="expanded ? t('memories', 'Collapse details') : t('memories', 'Expand details')"
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
/** Visible peek height: 45vh capped for tall screens */
const PEEK_PX = 340;

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
  },

  emits: ['close'],

  data: () => ({
    /** Docked at head/tail (vs peek) */
    expanded: false,
    dragging: false,
    closing: false,
    /** Resting top edge of the sheet in px */
    top: 0,
    /** Transient transform delta; always 0 at rest */
    ty: 0,
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
    glideTimer: 0,
    /** Transform glide in flight */
    gliding: false,
  }),

  watch: {
    photo: {
      handler() {
        this.refreshMetadata();
      },
    },
  },

  mounted() {
    this.refreshMetadata();
    this.$nextTick(() => this.enter());

    this.refs().sheet?.addEventListener('touchmove', this.onDragMove, { passive: false });
    // ResizeObserver batches per frame, so adjust synchronously
    // (pre-paint) to avoid flashing raw growth for a frame.
    this.resizeObserver = new ResizeObserver(() => this.layout());
    this.resizeObserver.observe(this.refs().content);
    this.resizeListener = () => this.layout();
    window.addEventListener('resize', this.resizeListener);
  },

  beforeUnmount() {
    this.refs().sheet?.removeEventListener('touchmove', this.onDragMove);
    this.resizeObserver?.disconnect();
    if (this.resizeListener) window.removeEventListener('resize', this.resizeListener);
    window.clearTimeout(this.closeTimer);
    window.clearTimeout(this.glideTimer);
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

    /** Full height of the embedded content; the sheet always wraps it all */
    fullHeight(): number {
      return this.refs().sheet?.scrollHeight ?? 0;
    },

    /** Top edge with the content end docked at the viewport bottom */
    tailTop(): number {
      return window.innerHeight - this.fullHeight();
    },

    /** Visible peek height */
    peekSize(): number {
      return Math.min(Math.round(window.innerHeight * 0.45), PEEK_PX);
    },

    /** Top edge showing just the peek area */
    peekTop(): number {
      return window.innerHeight - this.peekSize();
    },

    /** Top edge with the head fullscreen (handle at the viewport top) */
    headTop(): number {
      return Math.max(this.tailTop(), 0);
    },

    /** Merged detent list: tail, head, peek */
    spots(): number[] {
      const out: number[] = [];
      for (const s of [this.tailTop(), this.headTop(), this.peekTop()].sort((a, b) => a - b)) {
        if (!out.length || s - out.at(-1)! >= DOCK_PX) out.push(s);
      }
      return out;
    },

    /** Remember current detents to recognize parked positions later. */
    syncDetents() {
      this.lastRest = this.peekTop();
      this.lastHead = this.headTop();
      this.lastFull = this.fullHeight();
    },

    applyPos() {
      const sheet = this.refs().sheet;
      if (!sheet) return;
      sheet.style.setProperty('top', `${Math.round(this.top)}px`);
      sheet.style.setProperty('transform', `translateY(${Math.round(this.ty)}px)`);
    },

    applyTransform() {
      this.refs().sheet?.style.setProperty('transform', `translateY(${Math.round(this.ty)}px)`);
    },

    /** Live visual top edge, adopting any in-flight glide. */
    liveTop(): number {
      const sheet = this.refs().sheet;
      if (!sheet) return this.top;
      const cs = getComputedStyle(sheet);
      const top = parseFloat(cs.top);
      const m = cs.transform;
      const dy = m && m !== 'none' ? new DOMMatrixReadOnly(m).m42 : 0;
      return (Number.isFinite(top) ? top : this.top) + (Number.isFinite(dy) ? dy : 0);
    },

    /** Run fn with transitions off so the box jumps without animating. */
    freeze(sheet: HTMLElement, fn: () => void) {
      sheet.style.transition = 'none';
      fn();
      this.applyPos();
      void sheet.offsetHeight;
      sheet.style.transition = '';
    },

    /** Glide the visual top to target through transform; top stays
     * exact throughout, so content reflows can't disturb the motion. */
    glideTo(target: number, ms = 250, settle?: () => void) {
      const sheet = this.refs().sheet;
      const visual = this.liveTop();
      this.cancelGlide();
      this.top = target;
      this.ty = 0;
      if (!sheet || Math.abs(visual - target) < 1) {
        this.applyPos();
        this.syncDetents();
        settle?.();
        return;
      }
      this.gliding = true;
      sheet.style.transition = 'none';
      this.ty = visual - target;
      this.applyPos();
      void sheet.offsetHeight;
      sheet.style.transition = `transform ${ms}ms ease-out`;
      this.ty = 0;
      this.applyTransform();
      this.syncDetents();
      this.glideTimer = window.setTimeout(() => {
        if (!this.gliding) return;
        this.gliding = false;
        this.refs().sheet?.style.setProperty('transition', '');
        this.ty = 0;
        this.applyTransform();
        this.syncDetents();
        settle?.();
      }, ms + 60);
    },

    /** Drop a pending glide without moving. */
    cancelGlide() {
      if (!this.gliding) return;
      this.gliding = false;
      window.clearTimeout(this.glideTimer);
      this.refs().sheet?.style.setProperty('transition', '');
    },

    /** Initial slide-up from dismissed to peek. The travel is fixed,
     * so content reflows mid-flight extend downward undisturbed. */
    enter() {
      const sheet = this.refs().sheet;
      if (!sheet) return;
      this.freeze(sheet, () => {
        this.top = window.innerHeight;
        this.ty = 0;
      });
      this.expanded = false;
      const ms = Math.min(600, Math.max(200, Math.round(this.peekSize() / 1.5)));
      this.glideTo(this.peekTop(), ms, () => this.snapPeek());
    },

    // A docked tail follows content growth so the end stays docked;
    // peek and head are fixed viewport positions that growth extends
    // downward from. A freely panned sheet keeps its top instead.
    layout() {
      if (this.dragging || this.closing || this.gliding) return;
      const sheet = this.refs().sheet;
      if (!sheet) return;

      const full = this.fullHeight();
      const tail = window.innerHeight - full;
      const atTail = Math.abs(this.top - (window.innerHeight - this.lastFull)) < DOCK_PX;
      const atRest = Math.abs(this.top - this.lastRest) < DOCK_PX;
      const atHead = !atRest && Math.abs(this.top - this.lastHead) < DOCK_PX;
      if (full !== this.lastFull && atTail) {
        this.freeze(sheet, () => {
          this.top = tail;
          this.ty = 0;
        });
      }

      if (atRest) this.snapPeek();
      else if (atHead) this.snapHead();
      else {
        this.top = Math.min(window.innerHeight, Math.max(tail, this.top));
        this.ty = 0;
        this.syncDetents();
        this.applyPos();
      }
    },

    snapTo(top: number, expanded: boolean) {
      this.expanded = expanded;
      this.glideTo(top);
    },

    /** Dock at a top; anything but peek counts as expanded. */
    snapValue(target: number) {
      this.snapTo(target, target !== this.peekTop());
    },

    snapHead() {
      this.snapTo(this.headTop(), true);
    },

    snapPeek() {
      this.snapTo(this.peekTop(), false);
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
      // Grab the live position so a mid-glide grab never jumps.
      if (this.gliding) {
        this.top = this.liveTop();
        this.ty = 0;
        this.cancelGlide();
        this.syncDetents();
        this.applyPos();
      }
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
      this.finishPan();
      this.dragging = false;
    },

    /** Abort the gesture: snap back to the current dock. */
    onDragCancel() {
      this.dragging = false;
      this.snapTo(this.expanded ? this.headTop() : this.peekTop(), this.expanded);
    },

    /** Begin a 1:1 pan from the current top. */
    startPan(clientY: number, onHandle: boolean) {
      this.dragging = true;
      this.dragStartY = clientY;
      this.dragBase = this.top;
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

      // Follow the finger 1:1 through transform across the whole box,
      // from the tail down to dismissed. The handle may travel above
      // the viewport on long sheets; the sheet never lifts past
      // its own tail.
      const tail = window.innerHeight - this.fullHeight();
      this.ty = Math.min(window.innerHeight, Math.max(tail, this.dragBase + this.dragDy)) - this.top;
      this.applyTransform();
    },

    // Release: tap toggles, fling steps detents, far-down dismisses,
    // otherwise dock nearby or stay where dropped.
    finishPan() {
      // Commit the finger position first; with transitions off while
      // dragging this moves nothing, so the snaps below start live.
      this.top += this.ty;
      this.ty = 0;
      this.applyPos();

      const dy = this.dragDy;
      const vel = this.velocity;
      const rest = this.peekTop();

      // Taps on the handle toggle; taps elsewhere do nothing so
      // embedded links and buttons keep working normally.
      if (Math.abs(dy) < TAP_SLOP) {
        if (this.startedOnHandle) this.toggleOpen();
        return;
      }

      if (vel < -FLING_PX_MS) this.snapNeighbor(-1);
      else if (vel > FLING_PX_MS) this.snapNeighbor(1);
      else if (this.top > rest + CLOSE_PX) this.dismiss();
      else if (!this.snapNearest()) this.syncDetents();
    },

    // Step to the neighboring detent (+1 down, -1 up),
    // falling off the bottom edge into dismiss.
    snapNeighbor(dir: 1 | -1) {
      const spots = this.spots();
      let idx = 0;
      spots.forEach((s, i) => {
        if (s <= this.top + DOCK_PX) idx = i;
      });
      const next = idx + dir;
      if (next >= spots.length) {
        this.dismiss();
        return;
      }
      this.snapValue(spots[Math.max(0, next)]);
    },

    // Dock to a nearby detent; false to stay exactly where released.
    snapNearest() {
      const spots = this.spots();
      let near = spots[0];
      for (const s of spots) {
        if (Math.abs(s - this.top) < Math.abs(near - this.top)) near = s;
      }
      if (Math.abs(near - this.top) < DOCK_PX) {
        this.snapValue(near);
        return true;
      }
      return false;
    },

    /** Glide off-screen, then ask the parent to close. */
    dismiss() {
      this.closing = true;
      this.glideTo(window.innerHeight);
      this.closeTimer = window.setTimeout(() => this.$emit('close'), CLOSE_ANIM_MS);
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
  top: 0;
  z-index: 100002;

  // Embedded content: the sheet wraps it all and panning moves
  // the whole sheet, so the inside never scrolls.
  overflow: hidden;
  touch-action: none;
  will-change: transform;
  // Reserve peek space while metadata loads.
  min-height: min(45vh, 340px);
  min-height: min(45dvh, 340px);

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
  min-height: min(40vh, 300px);
  min-height: min(40dvh, 300px);

  // Loading wrapper fills the sheet, giving the absolutely-centered
  // spinner a definite box so it never collapses onto the handle.
  :deep(.loading-icon.fill-block) {
    position: absolute;
    inset: 0;
  }
}
</style>
