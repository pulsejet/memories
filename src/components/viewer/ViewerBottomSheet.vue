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

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import Metadata from '@components/Metadata.vue';

import type { IPhoto } from '@typings';

const TAP_SLOP = 10;
const CLOSE_PX = 80;
const DOCK_PX = 40;
const FLING_PX_MS = 0.5;
const CLOSE_ANIM_MS = 280;
/** Visible peek height: 45vh capped for tall screens */
const PEEK_PX = 340;

defineOptions({
  name: 'ViewerBottomSheet',
});

const props = withDefaults(
  defineProps<{
    photo?: IPhoto | null;
  }>(),
  {
    photo: null,
  },
);

const emit = defineEmits<{
  close: [];
}>();

const sheet = useTemplateRef<HTMLDivElement>('sheet');
const content = useTemplateRef<HTMLDivElement>('content');
const metadata = useTemplateRef<InstanceType<typeof Metadata>>('metadata');

/** Docked at head/tail (vs peek) */
const expanded = ref(false);
const dragging = ref(false);
const closing = ref(false);
/** Resting top edge of the sheet in px */
const top = ref(0);
/** Transient transform delta; always 0 at rest */
const ty = ref(0);
const dragStartY = ref(0);
const dragBase = ref(0);
const dragDy = ref(0);
const lastY = ref(0);
const lastT = ref(0);
const velocity = ref(0);
const startedOnHandle = ref(false);
const suppressClick = ref(false);
/** Last known detents, to tell parked positions from free panning */
const lastRest = ref(0);
const lastHead = ref(0);
const lastFull = ref(0);

let resizeObserver: ResizeObserver | null = null;
let resizeListener: (() => void) | null = null;
let closeTimer = 0;
let glideTimer = 0;
/** Transform glide in flight */
let gliding = false;

watch(
  () => props.photo,
  () => {
    refreshMetadata();
  },
);

onMounted(() => {
  refreshMetadata();
  nextTick(() => enter());

  sheet.value?.addEventListener('touchmove', onDragMove, { passive: false });
  // ResizeObserver batches per frame, so adjust synchronously
  // (pre-paint) to avoid flashing raw growth for a frame.
  resizeObserver = new ResizeObserver(() => layout());
  if (content.value) resizeObserver.observe(content.value);
  resizeListener = () => layout();
  window.addEventListener('resize', resizeListener);
});

onBeforeUnmount(() => {
  sheet.value?.removeEventListener('touchmove', onDragMove);
  resizeObserver?.disconnect();
  if (resizeListener) window.removeEventListener('resize', resizeListener);
  window.clearTimeout(closeTimer);
  window.clearTimeout(glideTimer);
});

/** Reload metadata for the current photo, then fit the sheet around it. */
async function refreshMetadata() {
  const photo = props.photo;
  if (!photo || metadata.value?.fileid === photo.fileid) return;
  await metadata.value?.update(photo);
  layout();
}

/** Full height of the embedded content; the sheet always wraps it all */
function fullHeight(): number {
  return sheet.value?.scrollHeight ?? 0;
}

/** Top edge with the content end docked at the viewport bottom */
function tailTop(): number {
  return window.innerHeight - fullHeight();
}

/** Visible peek height */
function peekSize(): number {
  return Math.min(Math.round(window.innerHeight * 0.45), PEEK_PX);
}

/** Top edge showing just the peek area */
function peekTop(): number {
  return window.innerHeight - peekSize();
}

/** Top edge with the head fullscreen (handle at the viewport top) */
function headTop(): number {
  return Math.max(tailTop(), 0);
}

/** Merged detent list: tail, head, peek */
function spots(): number[] {
  const out: number[] = [];
  for (const s of [tailTop(), headTop(), peekTop()].sort((a, b) => a - b)) {
    if (!out.length || s - out.at(-1)! >= DOCK_PX) out.push(s);
  }
  return out;
}

/** Remember current detents to recognize parked positions later. */
function syncDetents() {
  lastRest.value = peekTop();
  lastHead.value = headTop();
  lastFull.value = fullHeight();
}

function applyPos() {
  const el = sheet.value;
  if (!el) return;
  el.style.setProperty('top', `${Math.round(top.value)}px`);
  el.style.setProperty('transform', `translateY(${Math.round(ty.value)}px)`);
}

function applyTransform() {
  sheet.value?.style.setProperty('transform', `translateY(${Math.round(ty.value)}px)`);
}

/** Live visual top edge, adopting any in-flight glide. */
function liveTop(): number {
  const el = sheet.value;
  if (!el) return top.value;
  const cs = getComputedStyle(el);
  const topPx = parseFloat(cs.top);
  const m = cs.transform;
  const dy = m && m !== 'none' ? new DOMMatrixReadOnly(m).m42 : 0;
  return (Number.isFinite(topPx) ? topPx : top.value) + (Number.isFinite(dy) ? dy : 0);
}

/** Run fn with transitions off so the box jumps without animating. */
function freeze(el: HTMLElement, fn: () => void) {
  el.style.transition = 'none';
  fn();
  applyPos();
  void el.offsetHeight;
  el.style.transition = '';
}

/** Glide the visual top to target through transform; top stays
 * exact throughout, so content reflows can't disturb the motion. */
function glideTo(target: number, ms = 250, settle?: () => void) {
  const el = sheet.value;
  const visual = liveTop();
  cancelGlide();
  top.value = target;
  ty.value = 0;
  if (!el || Math.abs(visual - target) < 1) {
    applyPos();
    syncDetents();
    settle?.();
    return;
  }
  gliding = true;
  el.style.transition = 'none';
  ty.value = visual - target;
  applyPos();
  void el.offsetHeight;
  el.style.transition = `transform ${ms}ms ease-out`;
  ty.value = 0;
  applyTransform();
  syncDetents();
  glideTimer = window.setTimeout(() => {
    if (!gliding) return;
    gliding = false;
    sheet.value?.style.setProperty('transition', '');
    ty.value = 0;
    applyTransform();
    syncDetents();
    settle?.();
  }, ms + 60);
}

/** Drop a pending glide without moving. */
function cancelGlide() {
  if (!gliding) return;
  gliding = false;
  window.clearTimeout(glideTimer);
  sheet.value?.style.setProperty('transition', '');
}

/** Initial slide-up from dismissed to peek. The travel is fixed,
 * so content reflows mid-flight extend downward undisturbed. */
function enter() {
  const el = sheet.value;
  if (!el) return;
  freeze(el, () => {
    top.value = window.innerHeight;
    ty.value = 0;
  });
  expanded.value = false;
  const ms = Math.min(600, Math.max(200, Math.round(peekSize() / 1.5)));
  glideTo(peekTop(), ms, () => snapPeek());
}

// A docked tail follows content growth so the end stays docked;
// peek and head are fixed viewport positions that growth extends
// downward from. A freely panned sheet keeps its top instead.
function layout() {
  if (dragging.value || closing.value || gliding) return;
  const el = sheet.value;
  if (!el) return;

  const full = fullHeight();
  const tail = window.innerHeight - full;
  const atTail = Math.abs(top.value - (window.innerHeight - lastFull.value)) < DOCK_PX;
  const atRest = Math.abs(top.value - lastRest.value) < DOCK_PX;
  const atHead = !atRest && Math.abs(top.value - lastHead.value) < DOCK_PX;
  if (full !== lastFull.value && atTail) {
    freeze(el, () => {
      top.value = tail;
      ty.value = 0;
    });
  }

  if (atRest) snapPeek();
  else if (atHead) snapHead();
  else {
    top.value = Math.min(window.innerHeight, Math.max(tail, top.value));
    ty.value = 0;
    syncDetents();
    applyPos();
  }
}

function snapTo(topPx: number, expand: boolean) {
  expanded.value = expand;
  glideTo(topPx);
}

/** Dock at a top; anything but peek counts as expanded. */
function snapValue(target: number) {
  snapTo(target, target !== peekTop());
}

function snapHead() {
  snapTo(headTop(), true);
}

function snapPeek() {
  snapTo(peekTop(), false);
}

/** Handle tap/keyboard: alternate peek and head. */
function toggleOpen() {
  if (closing.value) return;
  if (expanded.value) snapPeek();
  else snapHead();
}

function panTarget(e: TouchEvent) {
  return (e.target as HTMLElement).closest('.sheet-handle-area') !== null;
}

function onDragStart(e: TouchEvent) {
  if (e.touches.length !== 1 || closing.value) return;
  // Embedded interactive content keeps its own gestures (e.g. map pan).
  if ((e.target as HTMLElement).closest('.leaflet-container')) return;
  // Grab the live position so a mid-glide grab never jumps.
  if (gliding) {
    top.value = liveTop();
    ty.value = 0;
    cancelGlide();
    syncDetents();
    applyPos();
  }
  startPan(e.touches[0].clientY, panTarget(e));
}

function onDragMove(e: TouchEvent) {
  if (!dragging.value || e.touches.length !== 1) return;
  // Take over the gesture so nothing behind scrolls or pans.
  if (e.cancelable) e.preventDefault();
  movePan(e.touches[0].clientY);
}

function onDragEnd() {
  if (!dragging.value) return;
  finishPan();
  dragging.value = false;
}

/** Abort the gesture: snap back to the current dock. */
function onDragCancel() {
  dragging.value = false;
  snapTo(expanded.value ? headTop() : peekTop(), expanded.value);
}

/** Begin a 1:1 pan from the current top. */
function startPan(clientY: number, onHandle: boolean) {
  dragging.value = true;
  dragStartY.value = clientY;
  dragBase.value = top.value;
  dragDy.value = 0;
  lastY.value = clientY;
  lastT.value = Date.now();
  velocity.value = 0;
  startedOnHandle.value = onHandle;
  suppressClick.value = false;
}

function movePan(clientY: number) {
  const now = Date.now();
  dragDy.value = clientY - dragStartY.value;
  if (Math.abs(dragDy.value) > TAP_SLOP) {
    suppressClick.value = true;
  }

  const dt = Math.max(1, now - lastT.value);
  velocity.value = 0.7 * velocity.value + (0.3 * (clientY - lastY.value)) / dt;
  lastY.value = clientY;
  lastT.value = now;

  // Follow the finger 1:1 through transform across the whole box,
  // from the tail down to dismissed. The handle may travel above
  // the viewport on long sheets; the sheet never lifts past
  // its own tail.
  const tail = window.innerHeight - fullHeight();
  ty.value = Math.min(window.innerHeight, Math.max(tail, dragBase.value + dragDy.value)) - top.value;
  applyTransform();
}

// Release: tap toggles, fling steps detents, far-down dismisses,
// otherwise dock nearby or stay where dropped.
function finishPan() {
  // Commit the finger position first; with transitions off while
  // dragging this moves nothing, so the snaps below start live.
  top.value += ty.value;
  ty.value = 0;
  applyPos();

  const dy = dragDy.value;
  const vel = velocity.value;
  const rest = peekTop();

  // Taps on the handle toggle; taps elsewhere do nothing so
  // embedded links and buttons keep working normally.
  if (Math.abs(dy) < TAP_SLOP) {
    if (startedOnHandle.value) toggleOpen();
    return;
  }

  if (vel < -FLING_PX_MS) snapNeighbor(-1);
  else if (vel > FLING_PX_MS) snapNeighbor(1);
  else if (top.value > rest + CLOSE_PX) dismiss();
  else if (!snapNearest()) syncDetents();
}

// Step to the neighboring detent (+1 down, -1 up),
// falling off the bottom edge into dismiss.
function snapNeighbor(dir: 1 | -1) {
  const spotList = spots();
  let idx = 0;
  spotList.forEach((s, i) => {
    if (s <= top.value + DOCK_PX) idx = i;
  });
  const next = idx + dir;
  if (next >= spotList.length) {
    dismiss();
    return;
  }
  snapValue(spotList[Math.max(0, next)]);
}

// Dock to a nearby detent; false to stay exactly where released.
function snapNearest() {
  const spotList = spots();
  let near = spotList[0];
  for (const s of spotList) {
    if (Math.abs(s - top.value) < Math.abs(near - top.value)) near = s;
  }
  if (Math.abs(near - top.value) < DOCK_PX) {
    snapValue(near);
    return true;
  }
  return false;
}

/** Glide off-screen, then ask the parent to close. */
function dismiss() {
  closing.value = true;
  glideTo(window.innerHeight);
  closeTimer = window.setTimeout(() => emit('close'), CLOSE_ANIM_MS);
}

function onClickCapture(e: Event) {
  // A drag starting on a link would otherwise navigate on release.
  if (suppressClick.value) {
    e.preventDefault();
    e.stopPropagation();
    suppressClick.value = false;
  }
}
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
