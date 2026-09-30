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
        type="tertiary"
        :aria-label="t('memories', 'Zoom out')"
        :disabled="scale <= MIN_SCALE"
        @click="zoomBy(1 / ZOOM_STEP)"
      >
        −
      </NcButton>
      <span class="zoom-level">{{ Math.round(scale * 100) }} %</span>
      <NcButton
        type="tertiary"
        :aria-label="t('memories', 'Zoom in')"
        :disabled="scale >= MAX_SCALE"
        @click="zoomBy(ZOOM_STEP)"
      >
        +
      </NcButton>
      <NcButton type="tertiary" :disabled="scale === MIN_SCALE" @click="resetView">
        {{ t('memories', 'Fit') }}
      </NcButton>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';
import Hammer from 'hammerjs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import type { Rect, StageFace, StageRegion } from './faceMarking';
import { rectFromPoints, toCss } from './faceMarking';

const MIN_SCALE = 1;
const MAX_SCALE = 8;
const ZOOM_STEP = 1.5;
/** Below this share of the photo a drag is a click, and draws nothing */
const MIN_DRAWN = 0.01;

type Point = { x: number; y: number };

/**
 * The hammerjs manager of each stage, kept out of the data of the component:
 * Vue would walk and observe the whole object graph of it, for nothing.
 */
const hammers = new WeakMap<object, HammerManager>();

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
export default defineComponent({
  name: 'FaceMarkingStage',
  components: { NcButton },

  props: {
    src: { type: String, required: true },
    faces: { type: Array as PropType<StageFace[]>, default: () => [] },
    regions: { type: Array as PropType<StageRegion[]>, default: () => [] },
    rect: { type: Object as PropType<Rect | null>, default: null },
    drawingEnabled: { type: Boolean, default: true },
  },

  emits: {
    'update:rect': (_rect: Rect | null) => true,
    /** A face was clicked; with Ctrl (Cmd on a Mac) it is added to the ones selected, or taken out */
    select: (_faceId: number, _additive: boolean) => true,
    'navigation-error': () => true,
    'image-error': () => true,
  },

  data: () => ({
    MIN_SCALE,
    MAX_SCALE,
    ZOOM_STEP,
    scale: MIN_SCALE,
    tx: 0,
    ty: 0,
    /** Pointers down on the photo, to tell one finger from two */
    pointerIds: [] as number[],
    /**
     * The rectangle being drawn, shown here while the pointer moves and only
     * reported when it is let go: the dialog shows its fields for a reported
     * one, and doing that halfway through the drag moved the page under it.
     */
    draw: null as { pointerId: number; start: Point; current: Rect | null } | null,
    /** The photo being moved with the middle mouse button */
    pan: null as { pointerId: number; start: Point; tx: number; ty: number } | null,
    /** A two finger gesture: what the photo was at its start */
    gesture: null as { scale: number; anchor: Point } | null,
  }),

  computed: {
    /** While drawing, the rectangle being drawn; otherwise the one there is. */
    shownRect(): Rect | null {
      return this.draw?.current ?? this.rect;
    },

    contentStyle(): Record<string, string> {
      return { transform: `translate(${this.tx}px, ${this.ty}px) scale(${this.scale})` };
    },
  },

  watch: {
    src() {
      this.resetView();
    },
  },

  mounted() {
    // Two fingers are the only thing that needs hammerjs. If it cannot be set
    // up, drawing does not depend on it, and the wheel and buttons still zoom.
    try {
      const hammer = new Hammer.Manager(this.$refs.viewport as HTMLElement, { touchAction: 'none' });
      const pinch = new Hammer.Pinch({ pointers: 2, threshold: 0 });
      const pan = new Hammer.Pan({ pointers: 2, threshold: 0, direction: Hammer.DIRECTION_ALL });
      pinch.recognizeWith(pan);
      hammer.add([pan, pinch]);
      hammer.on('panstart pinchstart', this.onGestureStart);
      hammer.on('panmove pinchmove', this.onGestureMove);
      hammer.on('panend pinchend pancancel pinchcancel', this.onGestureEnd);
      hammers.set(this, hammer);
    } catch (e) {
      console.error(e);
      this.$emit('navigation-error');
    }
  },

  beforeDestroy() {
    try {
      hammers.get(this)?.destroy();
    } catch (e) {
      console.error(e);
    }
    hammers.delete(this);
  },

  methods: {
    toCss,

    resetView() {
      this.scale = MIN_SCALE;
      this.tx = 0;
      this.ty = 0;
    },

    /** A point of the screen as fractions of the photo as it is shown now. */
    photoPoint(clientX: number, clientY: number): Point | null {
      const image = this.$refs.image as HTMLImageElement | undefined;
      if (!image) return null;
      const box = image.getBoundingClientRect();
      if (!box.width || !box.height) return null;
      return {
        x: Math.max(0, Math.min(1, (clientX - box.left) / box.width)),
        y: Math.max(0, Math.min(1, (clientY - box.top) / box.height)),
      };
    },

    /** A point of the screen in pixels of the viewport. */
    viewportPoint(clientX: number, clientY: number): Point {
      const box = (this.$refs.viewport as HTMLElement).getBoundingClientRect();
      return { x: clientX - box.left, y: clientY - box.top };
    },

    /** Moves the photo, but never so far that its edge leaves the viewport. */
    moveTo(tx: number, ty: number) {
      const viewport = this.$refs.viewport as HTMLElement | undefined;
      const width = viewport?.clientWidth ?? 0;
      const height = viewport?.clientHeight ?? 0;
      this.tx = Math.min(0, Math.max(width - width * this.scale, tx));
      this.ty = Math.min(0, Math.max(height - height * this.scale, ty));
    },

    /** Zooms so that the point of the photo under $at stays where it is. */
    zoomAround(scale: number, at: Point) {
      const next = Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale));
      const anchor = { x: (at.x - this.tx) / this.scale, y: (at.y - this.ty) / this.scale };
      this.scale = next;
      this.moveTo(at.x - anchor.x * next, at.y - anchor.y * next);
    },

    zoomBy(factor: number) {
      const viewport = this.$refs.viewport as HTMLElement;
      this.zoomAround(this.scale * factor, { x: viewport.clientWidth / 2, y: viewport.clientHeight / 2 });
    },

    onWheel(ev: WheelEvent) {
      this.zoomAround(this.scale * Math.exp(-ev.deltaY * 0.0015), this.viewportPoint(ev.clientX, ev.clientY));
    },

    /** The arrow keys move the photo, and + and - zoom it, for those without a pointer. */
    onKeyDown(ev: KeyboardEvent) {
      const viewport = this.$refs.viewport as HTMLElement;
      const step = Math.max(20, Math.round(viewport.clientWidth / 10));
      switch (ev.key) {
        case 'ArrowLeft':
          this.moveTo(this.tx + step, this.ty);
          break;
        case 'ArrowRight':
          this.moveTo(this.tx - step, this.ty);
          break;
        case 'ArrowUp':
          this.moveTo(this.tx, this.ty + step);
          break;
        case 'ArrowDown':
          this.moveTo(this.tx, this.ty - step);
          break;
        case '+':
        case '=':
          this.zoomBy(ZOOM_STEP);
          break;
        case '-':
          this.zoomBy(1 / ZOOM_STEP);
          break;
        case '0':
          this.resetView();
          break;
        default:
          return;
      }
      ev.preventDefault();
    },

    onPointerDown(ev: PointerEvent) {
      // The first pointer of a touch, or any mouse press, starts afresh, so a
      // pointer whose release got lost cannot count as a second finger forever.
      if (ev.isPrimary) {
        this.pointerIds = [];
      }
      if (!this.pointerIds.includes(ev.pointerId)) {
        this.pointerIds.push(ev.pointerId);
      }

      // A second finger is not drawing: it is the start of a gesture.
      if (this.pointerIds.length > 1) {
        this.cancelDrawing();
        return;
      }

      if (ev.pointerType === 'mouse' && ev.button === 1) {
        ev.preventDefault();
        this.pan = { pointerId: ev.pointerId, start: { x: ev.clientX, y: ev.clientY }, tx: this.tx, ty: this.ty };
        this.capture(ev);
        return;
      }

      // A pointer on a face picks that face, and draws nothing. It still
      // reaches hammerjs, so it can be one of the two fingers of a gesture.
      if ((ev.target as Element | null)?.closest?.('.face-box.existing')) return;

      if (ev.button !== 0 || !this.drawingEnabled) return;

      const point = this.photoPoint(ev.clientX, ev.clientY);
      if (!point) return;
      this.draw = { pointerId: ev.pointerId, start: point, current: null };
      this.capture(ev);
    },

    onPointerMove(ev: PointerEvent) {
      if (this.pan && ev.pointerId === this.pan.pointerId) {
        this.moveTo(this.pan.tx + ev.clientX - this.pan.start.x, this.pan.ty + ev.clientY - this.pan.start.y);
        return;
      }

      if (this.draw && ev.pointerId === this.draw.pointerId) {
        const point = this.photoPoint(ev.clientX, ev.clientY);
        if (point) {
          this.draw.current = rectFromPoints(this.draw.start, point);
        }
      }
    },

    onPointerUp(ev: PointerEvent) {
      this.release(ev);

      if (this.pan && ev.pointerId === this.pan.pointerId) {
        this.pan = null;
        return;
      }

      if (this.draw && ev.pointerId === this.draw.pointerId) {
        const drawn = this.draw.current;
        this.draw = null;
        // A click is not a rectangle, and must not lose the one there was.
        if (drawn && drawn.w >= MIN_DRAWN && drawn.h >= MIN_DRAWN) {
          this.$emit('update:rect', drawn);
        }
      }
    },

    onPointerCancel(ev: PointerEvent) {
      this.release(ev);
      if (this.pan && ev.pointerId === this.pan.pointerId) {
        this.pan = null;
      }
      if (this.draw && ev.pointerId === this.draw.pointerId) {
        this.cancelDrawing();
      }
    },

    /** Stops drawing; the rectangle there was before was never replaced. */
    cancelDrawing() {
      this.draw = null;
    },

    capture(ev: PointerEvent) {
      try {
        (this.$refs.viewport as HTMLElement).setPointerCapture(ev.pointerId);
      } catch {
        // Capture only keeps the drag alive outside the photo.
      }
    },

    release(ev: PointerEvent) {
      this.pointerIds = this.pointerIds.filter((id) => id !== ev.pointerId);
      try {
        (this.$refs.viewport as HTMLElement).releasePointerCapture(ev.pointerId);
      } catch {
        // Not captured.
      }
    },

    onGestureStart(ev: HammerInput) {
      if (this.gesture) return;
      this.cancelDrawing();
      const center = this.viewportPoint(ev.center.x, ev.center.y);
      this.gesture = {
        scale: this.scale,
        anchor: { x: (center.x - this.tx) / this.scale, y: (center.y - this.ty) / this.scale },
      };
    },

    onGestureMove(ev: HammerInput) {
      if (!this.gesture) return;
      const center = this.viewportPoint(ev.center.x, ev.center.y);
      const scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, this.gesture.scale * (ev.scale || 1)));
      this.scale = scale;
      this.moveTo(center.x - this.gesture.anchor.x * scale, center.y - this.gesture.anchor.y * scale);
    },

    onGestureEnd() {
      this.gesture = null;
    },
  },
});
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
