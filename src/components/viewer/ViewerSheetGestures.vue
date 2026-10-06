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

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const SHEET_SWIPE_UP_PX = 70;
// Zoom ratio above initial that still counts as "not zoomed"
// (tolerates float noise so only a real zoom ignores the swipe).
const SHEET_ZOOM_TOLERANCE = 1.05;

/** PhotoSwipe surface the gestures need */
interface SheetPhotoSwipe {
  on(name: string, fn: (e: any) => void): void;
  off(name: string, fn: (e: any) => void): void;
  element?: HTMLDivElement | null;
  gestures?: { isMultitouch: boolean };
  currSlide?: {
    pan: { y: number };
    currZoomLevel: number;
    zoomLevels: { initial: number };
  } | null;
}

defineOptions({
  name: 'ViewerSheetGestures',
});

const props = withDefaults(
  defineProps<{
    /** Live PhotoSwipe instance driving the viewer */
    photoswipe?: SheetPhotoSwipe | null;
  }>(),
  {
    photoswipe: null,
  },
);

const emit = defineEmits<{
  open: [];
}>();

/** Finger position and pan at the current PhotoSwipe gesture start */
const downClientX = ref(0);
const downClientY = ref<number | null>(null);
const downPanY = ref(0);
/** Element the fallback swipe detector is bound to, if any */
const fallbackEl = ref<HTMLElement | null>(null);
/** Fallback swipe tracking on viewer slides */
const fbStartX = ref(0);
const fbStartY = ref(0);
const fbStartT = ref(0);
const fbTracking = ref(false);

watch(
  () => props.photoswipe,
  () => {
    setupGestures();
  },
);

onMounted(() => {
  setupGestures();
});

onBeforeUnmount(() => {
  teardownGestures();
});

/** True when the current slide is more than a little zoomed in. */
function isZoomed() {
  const slide = props.photoswipe?.currSlide;
  const curr = slide?.currZoomLevel;
  const initial = slide?.zoomLevels?.initial;
  if (!Number.isFinite(curr) || !Number.isFinite(initial) || !initial) return false;
  return curr! > initial! * SHEET_ZOOM_TOLERANCE;
}

/** Open the sheet on a sufficient upward swipe (dy<0). */
function maybeOpenSheet(dy: number, dx = 0, dt = 0) {
  if (isZoomed()) return;
  if (dy < -SHEET_SWIPE_UP_PX && Math.abs(dy) > 1.8 * Math.abs(dx) && dt < 800) {
    emit('open');
  }
}

/** Wire swipe-up gestures to the PhotoSwipe instance. */
function setupGestures() {
  // Teardown first so repeat calls never double-bind.
  teardownGestures();
  const pswp = props.photoswipe;
  if (!pswp) return;

  pswp.on('pointerDown', onPsPointerDown);
  pswp.on('pointerUp', onPsPointerUp);
  pswp.on('pointerMove', onPsPointerMove);
  pswp.on('verticalDrag', onPsVerticalDrag);
}

function teardownGestures() {
  // Listeners are stable method references, so unbinding
  // what was never bound is a safe no-op.
  props.photoswipe?.off('pointerDown', onPsPointerDown);
  props.photoswipe?.off('pointerUp', onPsPointerUp);
  props.photoswipe?.off('pointerMove', onPsPointerMove);
  props.photoswipe?.off('verticalDrag', onPsVerticalDrag);
  unbindFallback();
}

/**
 * Fallback swipe detector for content where verticalDrag does not
 * fire (e.g. video controls refusing the gesture). Scoped to the
 * PhotoSwipe element, so sheet and viewer chrome never reach it.
 */
function bindFallback() {
  const el = props.photoswipe?.element;
  if (!el || fallbackEl.value) return;
  fallbackEl.value = el;
  el.addEventListener('touchstart', onFallbackTouchStart, { passive: true });
  el.addEventListener('touchend', onFallbackTouchEnd, { passive: true });
}

function unbindFallback() {
  if (!fallbackEl.value) return;
  fallbackEl.value.removeEventListener('touchstart', onFallbackTouchStart);
  fallbackEl.value.removeEventListener('touchend', onFallbackTouchEnd);
  fallbackEl.value = null;
}

function onPsPointerDown(e: { originalEvent: PointerEvent }) {
  downClientX.value = e.originalEvent.clientX;
  downClientY.value = e.originalEvent.clientY;
  downPanY.value = props.photoswipe?.currSlide?.pan.y ?? 0;
  // The element only exists after init, which any gesture implies.
  bindFallback();
}

function onPsPointerUp() {
  downClientY.value = null;
}

function onPsPointerMove(e: { originalEvent: PointerEvent }) {
  if (downClientY.value === null) return;
  if (props.photoswipe?.gestures?.isMultitouch) return;
  maybeOpenSheet(e.originalEvent.clientY - downClientY.value, e.originalEvent.clientX - downClientX.value);
}

function onPsVerticalDrag(e: { panY: number; preventDefault(): void }) {
  if (isZoomed()) return;
  if (e.panY - downPanY.value >= 0) return;
  e.preventDefault();
}

/** Fallback swipe start; buttons and the sphere keep their own behavior. */
function onFallbackTouchStart(e: TouchEvent) {
  if (e.touches.length !== 1) {
    fbTracking.value = false;
    return;
  }
  if ((e.target as HTMLElement).closest('button, .memories-photosphere')) {
    fbTracking.value = false;
    return;
  }
  fbStartX.value = e.touches[0].clientX;
  fbStartY.value = e.touches[0].clientY;
  fbStartT.value = Date.now();
  fbTracking.value = true;
}

/** Fallback swipe end; opens on a quick, mostly-vertical swipe up. */
function onFallbackTouchEnd(e: TouchEvent) {
  if (!fbTracking.value) return;
  fbTracking.value = false;
  const t = e.changedTouches[0];
  if (!t) return;
  maybeOpenSheet(t.clientY - fbStartY.value, t.clientX - fbStartX.value, Date.now() - fbStartT.value);
}
</script>
