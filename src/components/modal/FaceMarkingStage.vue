<template>
  <div class="face-marking-stage">
    <div
      ref="viewport"
      class="viewport"
      :class="{ 'can-draw': drawingEnabled, panning: !!pan }"
      tabindex="0"
      :aria-label="t('memories', 'Photo. Use the arrow keys to move it and + or - to zoom.')"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerCancel"
      @wheel.prevent="onWheel"
      @keydown="onKeyDown"
      @auxclick.prevent
      @contextmenu.prevent
    >
      <div class="content" :style="contentStyle">
        <img ref="image" class="photo" :src="src" draggable="false" @load="resetView" @error="$emit('image-error')" />

        <div
          v-for="region in regions"
          :key="`region-${region.id}`"
          class="region-box"
          :class="region.classes"
          :style="toCss(region.rect)"
          :title="region.title"
        />

        <div
          v-for="face in faces"
          :key="face.id"
          class="face-box"
          :class="face.classes"
          :style="toCss(face.rect)"
          :title="face.title"
          tabindex="0"
          role="button"
          :aria-label="face.title"
          @click.stop="$emit('select', face.id, $event.ctrlKey || $event.metaKey)"
          @keydown.enter.prevent.stop="$emit('select', face.id, $event.ctrlKey || $event.metaKey)"
          @keydown.space.prevent.stop="$emit('select', face.id, $event.ctrlKey || $event.metaKey)"
        >
          <span v-if="face.label" class="label">{{ face.label }}</span>
        </div>

        <div v-if="shownRect" class="face-box drawing" :style="toCss(shownRect)" />
      </div>
    </div>

    <div class="zoom-controls">
      <NcButton
        variant="tertiary"
        :aria-label="t('memories', 'Zoom out')"
        :disabled="scale <= MIN_SCALE"
        @click="zoomBy(1 / ZOOM_STEP)"
      >
        −
      </NcButton>
      <span class="zoom-level">{{ Math.round(scale * 100) }} %</span>
      <NcButton
        variant="tertiary"
        :aria-label="t('memories', 'Zoom in')"
        :disabled="scale >= MAX_SCALE"
        @click="zoomBy(ZOOM_STEP)"
      >
        +
      </NcButton>
      <NcButton variant="tertiary" :disabled="scale === MIN_SCALE" @click="resetView">
        {{ t('memories', 'Fit') }}
      </NcButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';

import { t } from '@services/l10n';

import type { Rect, StageFace, StageRegion } from './faceMarking';
import { rectFromPoints, toCss } from './faceMarking';

const MIN_SCALE = 1;
const MAX_SCALE = 8;
const ZOOM_STEP = 1.5;
/** Below this share of the photo a drag is a click, and draws nothing */
const MIN_DRAWN = 0.01;

type Point = { x: number; y: number };

/**
 * A photo to mark faces on: one pointer draws a rectangle, and the photo can
 * be zoomed and moved without switching modes. Two fingers move and zoom, and
 * so do the mouse wheel and the middle mouse button; the primary button and a
 * single finger always draw.
 *
 * Everything drawn on the photo is placed in fractions of it, inside the
 * element that is zoomed, so the boxes stay on their faces at any zoom, and a
 * rectangle is measured against what is shown, wherever the photo was moved.
 */
defineOptions({
  name: 'FaceMarkingStage',
});

const props = withDefaults(
  defineProps<{
    src: string;
    faces?: StageFace[];
    regions?: StageRegion[];
    rect?: Rect | null;
    drawingEnabled?: boolean;
  }>(),
  {
    faces: () => [],
    regions: () => [],
    rect: null,
    drawingEnabled: true,
  },
);

const emit = defineEmits<{
  'update:rect': [rect: Rect | null];
  /** A face was clicked; with Ctrl (Cmd on a Mac) it is added to the ones selected, or taken out */
  select: [faceId: number, additive: boolean];
  'navigation-error': [];
  'image-error': [];
}>();

const viewport = useTemplateRef<HTMLElement>('viewport');
const image = useTemplateRef<HTMLImageElement>('image');

const scale = ref(MIN_SCALE);
const tx = ref(0);
const ty = ref(0);
/**
 * The rectangle being drawn, shown here while the pointer moves and only
 * reported when it is let go: the dialog shows its fields for a reported
 * one, and doing that halfway through the drag moved the page under it.
 */
const draw = ref<{ pointerId: number; start: Point; current: Rect | null } | null>(null);
/** The photo being moved with the middle mouse button */
const pan = ref<{ pointerId: number; start: Point; tx: number; ty: number } | null>(null);

/** Pointers down on the photo, to tell one finger from two */
let pointerIds: number[] = [];
/** A two finger gesture: what the photo was at its start */
let gesture: { scale: number; anchor: Point } | null = null;
/** The hammerjs manager, outside of the reactive state: Vue would observe all of it, for nothing */
let hammer: HammerManager | null = null;

/** While drawing, the rectangle being drawn; otherwise the one there is. */
const shownRect = computed(() => draw.value?.current ?? props.rect);

const contentStyle = computed(() => ({
  transform: `translate(${tx.value}px, ${ty.value}px) scale(${scale.value})`,
}));

watch(
  () => props.src,
  () => resetView(),
);

onMounted(() => {
  void setupGestures();
});

onBeforeUnmount(() => {
  try {
    hammer?.destroy();
  } catch (e) {
    console.error(e);
  }
  hammer = null;
});

/**
 * Two fingers are the only thing that needs hammerjs, so it is loaded here.
 * If it cannot be set up, drawing does not depend on it, and the wheel and
 * buttons still zoom.
 */
async function setupGestures() {
  try {
    const { default: Hammer } = await import('hammerjs');
    if (!viewport.value) return; // unmounted meanwhile

    hammer = new Hammer.Manager(viewport.value, { touchAction: 'none' });
    const pinch = new Hammer.Pinch({ pointers: 2, threshold: 0 });
    const twoFingerPan = new Hammer.Pan({ pointers: 2, threshold: 0, direction: Hammer.DIRECTION_ALL });
    pinch.recognizeWith(twoFingerPan);
    hammer.add([twoFingerPan, pinch]);
    hammer.on('panstart pinchstart', onGestureStart);
    hammer.on('panmove pinchmove', onGestureMove);
    hammer.on('panend pinchend pancancel pinchcancel', onGestureEnd);
  } catch (e) {
    console.error(e);
    emit('navigation-error');
  }
}

function resetView() {
  scale.value = MIN_SCALE;
  tx.value = 0;
  ty.value = 0;
}

/** A point of the screen as fractions of the photo as it is shown now. */
function photoPoint(clientX: number, clientY: number): Point | null {
  if (!image.value) return null;
  const box = image.value.getBoundingClientRect();
  if (!box.width || !box.height) return null;
  return {
    x: Math.max(0, Math.min(1, (clientX - box.left) / box.width)),
    y: Math.max(0, Math.min(1, (clientY - box.top) / box.height)),
  };
}

/** A point of the screen in pixels of the viewport. */
function viewportPoint(clientX: number, clientY: number): Point {
  const box = viewport.value!.getBoundingClientRect();
  return { x: clientX - box.left, y: clientY - box.top };
}

/** Moves the photo, but never so far that its edge leaves the viewport. */
function moveTo(x: number, y: number) {
  const width = viewport.value?.clientWidth ?? 0;
  const height = viewport.value?.clientHeight ?? 0;
  tx.value = Math.min(0, Math.max(width - width * scale.value, x));
  ty.value = Math.min(0, Math.max(height - height * scale.value, y));
}

/** Zooms so that the point of the photo under $at stays where it is. */
function zoomAround(next: number, at: Point) {
  const clamped = Math.max(MIN_SCALE, Math.min(MAX_SCALE, next));
  const anchor = { x: (at.x - tx.value) / scale.value, y: (at.y - ty.value) / scale.value };
  scale.value = clamped;
  moveTo(at.x - anchor.x * clamped, at.y - anchor.y * clamped);
}

function zoomBy(factor: number) {
  const el = viewport.value!;
  zoomAround(scale.value * factor, { x: el.clientWidth / 2, y: el.clientHeight / 2 });
}

function onWheel(ev: WheelEvent) {
  zoomAround(scale.value * Math.exp(-ev.deltaY * 0.0015), viewportPoint(ev.clientX, ev.clientY));
}

/** The arrow keys move the photo, and + and - zoom it, for those without a pointer. */
function onKeyDown(ev: KeyboardEvent) {
  const step = Math.max(20, Math.round(viewport.value!.clientWidth / 10));
  switch (ev.key) {
    case 'ArrowLeft':
      moveTo(tx.value + step, ty.value);
      break;
    case 'ArrowRight':
      moveTo(tx.value - step, ty.value);
      break;
    case 'ArrowUp':
      moveTo(tx.value, ty.value + step);
      break;
    case 'ArrowDown':
      moveTo(tx.value, ty.value - step);
      break;
    case '+':
    case '=':
      zoomBy(ZOOM_STEP);
      break;
    case '-':
      zoomBy(1 / ZOOM_STEP);
      break;
    case '0':
      resetView();
      break;
    default:
      return;
  }
  ev.preventDefault();
}

function onPointerDown(ev: PointerEvent) {
  // The first pointer of a touch, or any mouse press, starts afresh, so a
  // pointer whose release got lost cannot count as a second finger forever.
  if (ev.isPrimary) {
    pointerIds = [];
  }
  if (!pointerIds.includes(ev.pointerId)) {
    pointerIds = [...pointerIds, ev.pointerId];
  }

  // A second finger is not drawing: it is the start of a gesture.
  if (pointerIds.length > 1) {
    cancelDrawing();
    return;
  }

  if (ev.pointerType === 'mouse' && ev.button === 1) {
    ev.preventDefault();
    pan.value = { pointerId: ev.pointerId, start: { x: ev.clientX, y: ev.clientY }, tx: tx.value, ty: ty.value };
    capture(ev);
    return;
  }

  // A pointer on a face picks that face, and draws nothing. It still
  // reaches hammerjs, so it can be one of the two fingers of a gesture.
  if ((ev.target as Element | null)?.closest?.('.face-box.existing')) return;

  if (ev.button !== 0 || !props.drawingEnabled) return;

  const point = photoPoint(ev.clientX, ev.clientY);
  if (!point) return;
  draw.value = { pointerId: ev.pointerId, start: point, current: null };
  capture(ev);
}

function onPointerMove(ev: PointerEvent) {
  const panning = pan.value;
  if (panning && ev.pointerId === panning.pointerId) {
    moveTo(panning.tx + ev.clientX - panning.start.x, panning.ty + ev.clientY - panning.start.y);
    return;
  }

  const drawing = draw.value;
  if (drawing && ev.pointerId === drawing.pointerId) {
    const point = photoPoint(ev.clientX, ev.clientY);
    if (point) {
      draw.value = { ...drawing, current: rectFromPoints(drawing.start, point) };
    }
  }
}

function onPointerUp(ev: PointerEvent) {
  release(ev);

  if (pan.value && ev.pointerId === pan.value.pointerId) {
    pan.value = null;
    return;
  }

  if (draw.value && ev.pointerId === draw.value.pointerId) {
    const drawn = draw.value.current;
    draw.value = null;
    // A click is not a rectangle, and must not lose the one there was.
    if (drawn && drawn.w >= MIN_DRAWN && drawn.h >= MIN_DRAWN) {
      emit('update:rect', drawn);
    }
  }
}

function onPointerCancel(ev: PointerEvent) {
  release(ev);
  if (pan.value && ev.pointerId === pan.value.pointerId) {
    pan.value = null;
  }
  if (draw.value && ev.pointerId === draw.value.pointerId) {
    cancelDrawing();
  }
}

/** Stops drawing; the rectangle there was before was never replaced. */
function cancelDrawing() {
  draw.value = null;
}

function capture(ev: PointerEvent) {
  try {
    viewport.value?.setPointerCapture(ev.pointerId);
  } catch {
    // Capture only keeps the drag alive outside the photo.
  }
}

function release(ev: PointerEvent) {
  pointerIds = pointerIds.filter((id) => id !== ev.pointerId);
  try {
    viewport.value?.releasePointerCapture(ev.pointerId);
  } catch {
    // Not captured.
  }
}

function onGestureStart(ev: HammerInput) {
  if (gesture) return;
  cancelDrawing();
  const center = viewportPoint(ev.center.x, ev.center.y);
  gesture = {
    scale: scale.value,
    anchor: { x: (center.x - tx.value) / scale.value, y: (center.y - ty.value) / scale.value },
  };
}

function onGestureMove(ev: HammerInput) {
  if (!gesture) return;
  const center = viewportPoint(ev.center.x, ev.center.y);
  const next = Math.max(MIN_SCALE, Math.min(MAX_SCALE, gesture.scale * (ev.scale || 1)));
  scale.value = next;
  moveTo(center.x - gesture.anchor.x * next, center.y - gesture.anchor.y * next);
}

function onGestureEnd() {
  gesture = null;
}
</script>

<style lang="scss" scoped>
.face-marking-stage {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  max-width: 100%;
}

.viewport {
  position: relative;
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  user-select: none;
  touch-action: none;
  background: var(--color-background-dark);

  &.can-draw {
    cursor: crosshair;
  }

  &.panning {
    cursor: grabbing;
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
  }
}

.content {
  position: relative;
  transform-origin: 0 0;

  .photo {
    display: block;
    max-width: 100%;
    max-height: 60vh;
    height: auto;
    pointer-events: none;
  }
}

.region-box {
  position: absolute;
  box-sizing: border-box;
  pointer-events: none;
  border: 2px dashed #3498db;

  &.region-failed {
    border: 2px dotted #e74c3c;
  }

  &.region-done {
    border: 1px dashed rgba(52, 152, 219, 0.6);
  }

  // The area the pointer is on in the list below the photo, to see which it is.
  &.highlighted {
    border: 3px solid #3498db;
    background: rgba(52, 152, 219, 0.15);
  }
}

.face-box {
  position: absolute;
  box-sizing: border-box;
  pointer-events: none;

  &.existing {
    pointer-events: auto;
    cursor: pointer;
    border-width: 2px;
    border-style: solid;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.4);

    // The colour tells where the face came from…
    &.origin-auto {
      border-color: #2ecc71;
    }
    &.origin-manual {
      border-color: #f1c40f;
    }
    &.origin-unknown {
      border-color: #95a5a6;
    }

    // …and the line whether it takes part in the automatic recognition, so
    // that the two can be told apart without seeing colours.
    &.clustering-pending {
      border-style: dashed;
    }
    &.clustering-excluded {
      border-style: dotted;
      border-width: 3px;
    }
    &.clustering-unknown {
      border-style: double;
      border-width: 4px;
    }

    // An ignored face is only there faintly, whatever it is otherwise.
    &.ignored {
      border: 1px dotted rgba(236, 240, 241, 0.8);
      box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.25);
      opacity: 0.55;

      &:hover,
      &.selected {
        opacity: 1;
      }
    }

    &.selected,
    &:focus-visible {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(230, 126, 34, 0.9),
        0 0 0 4px rgba(0, 0, 0, 0.4);
    }

    .label {
      position: absolute;
      bottom: -1.4em;
      left: 0;
      font-size: 11px;
      background: rgba(0, 0, 0, 0.65);
      color: #fff;
      padding: 1px 4px;
      border-radius: 2px;
      white-space: nowrap;
    }
  }

  &.drawing {
    border: 2px dashed #3498db;
    background: rgba(52, 152, 219, 0.15);
  }
}

.zoom-controls {
  display: flex;
  align-items: center;
  gap: 4px;

  .zoom-level {
    min-width: 4em;
    text-align: center;
    font-size: 0.9em;
    opacity: 0.8;
  }
}
</style>
