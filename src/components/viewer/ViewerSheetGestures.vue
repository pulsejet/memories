<!--
  Renderless swipe-up detector for the mobile bottom sheet.

  Mounted alongside the viewer on mobile (not only while the sheet is
  open), so opening gestures work while nothing is rendered. Emits
  `open` on a sufficient upward swipe; the parent owns all state and
  decides. PhotoSwipe itself stays non-reactive: only a minimal
  event/pan surface is used, never stored beyond listener teardown.
-->
<template>
  <!-- Intentionally empty: detects gestures, renders nothing. -->
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { PropType } from 'vue';

const SHEET_SWIPE_UP_PX = 70;

/** PhotoSwipe surface the gestures need */
interface SheetPhotoSwipe {
  on(name: string, fn: (e: any) => void): void;
  off(name: string, fn: (e: any) => void): void;
  element?: HTMLDivElement | null;
  gestures?: { isMultitouch: boolean };
  currSlide?: { pan: { y: number } } | null;
}

export default defineComponent({
  name: 'ViewerSheetGestures',

  props: {
    /** Live PhotoSwipe instance driving the viewer */
    photoswipe: {
      type: Object as PropType<SheetPhotoSwipe | null>,
      default: null,
    },
  },

  emits: ['open'],

  data: () => ({
    /** Finger position and pan at the current PhotoSwipe gesture start */
    downClientX: 0,
    downClientY: null as number | null,
    downPanY: 0,
    /** Element the fallback swipe detector is bound to, if any */
    fallbackEl: null as HTMLElement | null,
    /** Fallback swipe tracking on viewer slides */
    fbStartX: 0,
    fbStartY: 0,
    fbStartT: 0,
    fbTracking: false,
  }),

  watch: {
    photoswipe() {
      this.setupGestures();
    },
  },

  mounted() {
    this.setupGestures();
  },

  beforeUnmount() {
    this.teardownGestures();
  },

  methods: {
    /** Open the sheet on a sufficient upward swipe (dy<0). */
    maybeOpenSheet(dy: number, dx = 0, dt = 0) {
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

    onPsPointerMove(e: { originalEvent: PointerEvent }) {
      if (this.downClientY === null) return;
      if (this.photoswipe?.gestures?.isMultitouch) return;
      this.maybeOpenSheet(e.originalEvent.clientY - this.downClientY, e.originalEvent.clientX - this.downClientX);
    },

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
      const t = e.changedTouches[0];
      if (!t) return;
      this.maybeOpenSheet(t.clientY - this.fbStartY, t.clientX - this.fbStartX, Date.now() - this.fbStartT);
    },
  },
});
</script>
