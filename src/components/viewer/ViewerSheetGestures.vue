<!--
  Renderless flick-up detector for the mobile bottom sheet.

  Mounted alongside the viewer on mobile (not only while the sheet is
  open), so opening gestures work while nothing is rendered. Emits
  `open` on a Hammer swipeup (fast flick, not a slow drag); the parent
  owns all state and decides. PhotoSwipe itself stays non-reactive:
  only a minimal event surface is used, never stored beyond teardown.
-->
<template>
  <!-- Intentionally empty: detects gestures, renders nothing. -->
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

// Flick definition: fast enough to count as a fling, far enough
// to not be noise. Slow pans never open the sheet.
const FLICK_THRESHOLD_PX = 20;
const FLICK_VELOCITY_PX_MS = 0.5;
// Zoom ratio above initial that still counts as "not zoomed"
// (tolerates float noise so only a real zoom ignores the flick).
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

/** Bound instance, if any */
let pswp: SheetPhotoSwipe | null = null;
let hammer: HammerManager | null = null;
let boundEl: HTMLElement | null = null;
/** Token to drop stale async binds after teardown */
let bindToken = 0;
/** Slide pan at the current gesture start (for verticalDrag guard) */
let downPanY = 0;

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
  const slide = (pswp ?? props.photoswipe)?.currSlide;
  const curr = slide?.currZoomLevel;
  const initial = slide?.zoomLevels?.initial;
  if (!Number.isFinite(curr) || !Number.isFinite(initial) || !initial) return false;
  return curr! > initial! * SHEET_ZOOM_TOLERANCE;
}

/** Wire flick-up detection to the PhotoSwipe instance. */
function setupGestures() {
  // Teardown first so repeat calls never double-bind.
  teardownGestures();
  pswp = props.photoswipe;
  if (!pswp) return;

  pswp.on('pointerDown', onPointerDown);
  pswp.on('verticalDrag', onVerticalDrag);
  pswp.on('openingAnimationStart', bindHammer);
}

function teardownGestures() {
  bindToken++;
  // Listeners are stable references, so unbinding
  // what was never bound is a safe no-op.
  try {
    pswp?.off('pointerDown', onPointerDown);
    pswp?.off('verticalDrag', onVerticalDrag);
    pswp?.off('openingAnimationStart', bindHammer);
  } catch {
    // Never let teardown throw on a half-torn-down instance.
  }
  pswp = null;
  hammer?.destroy();
  hammer = null;
  boundEl = null;
}

/** Bind Hammer to the PhotoSwipe element once it exists. */
async function bindHammer() {
  const target = pswp ?? props.photoswipe;
  const el = target?.element;
  if (!target || !el || boundEl === el) return;
  const token = bindToken;

  const { default: Hammer } = await import('hammerjs');
  if (token !== bindToken || (pswp ?? props.photoswipe) !== target || target.element !== el) return;
  if (boundEl === el) return;
  if (hammer) {
    hammer.destroy();
    hammer = null;
    boundEl = null;
  }

  hammer = new Hammer(el);
  hammer.get('swipe').set({
    direction: Hammer.DIRECTION_VERTICAL,
    threshold: FLICK_THRESHOLD_PX,
    velocity: FLICK_VELOCITY_PX_MS,
  });
  hammer.on('swipeup', onSwipeUp);
  boundEl = el;
}

function onPointerDown() {
  downPanY = (pswp ?? props.photoswipe)?.currSlide?.pan.y ?? 0;
}

/** Keep upward drags from closing the viewer; the sheet owns them. */
function onVerticalDrag(e: { panY: number; preventDefault(): void }) {
  if (isZoomed()) return;
  if (e.panY - downPanY >= 0) return;
  e.preventDefault();
}

/** Flick up (and only flick up) opens the sheet. */
function onSwipeUp(e: any) {
  if (isZoomed()) return;
  if ((pswp ?? props.photoswipe)?.gestures?.isMultitouch) return;
  if ((e?.target as HTMLElement | null)?.closest?.('button, a, .memories-photosphere')) return;
  emit('open');
}
</script>
