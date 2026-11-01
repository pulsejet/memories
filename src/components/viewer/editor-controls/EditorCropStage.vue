<template>
  <div
    ref="stageEl"
    class="me-crop-stage"
    :class="{
      'is-crop-mode': isCropMode,
      'is-interacting': interacting,
    }"
    @pointerdown="onPanStart"
  >
    <div class="me-crop-shift" :class="{ 'is-animated': animateTransform }" :style="shiftStyle">
      <div
        ref="turntableEl"
        class="me-crop-turntable"
        :class="{ 'is-animated': turntableAnimated }"
        :style="turntableStyle"
      >
        <div ref="mediaEl" class="me-crop-media" :class="{ 'is-animated': animateTransform }" :style="mediaStyle">
          <slot />
        </div>

        <EditorCropHandles
          :width="boxRect.w"
          :height="boxRect.h"
          :offset-x="boxRect.x"
          :offset-y="boxRect.y"
          :crop-mode="isCropMode"
          :active="interacting"
          :dragging="draggingHandle"
          :straightening="straightening"
          :animated="animateTransform"
          @handle-down="onHandleStart"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EditorCropHandles from './EditorCropHandles.vue';
import {
  applyPanDelta,
  effectiveCrop,
  fitAspectRatio,
  orientedDims,
  parseRatio,
  rotateCrop90,
  solveHandleDrag,
  solvePinch,
} from './cropMath';
import type { HandleDragContext, HandleDragResult, PinchContext } from './cropMath';
import type { CropHandle, EditorSection, EditorState } from './types';

const props = withDefaults(
  defineProps<{
    state: EditorState;
    section: EditorSection;
    imageWidth?: number;
    imageHeight?: number;
    /** Height covered by overlaid controls at the bottom of the stage. */
    bottomInset?: number;
  }>(),
  { imageWidth: 0, imageHeight: 0, bottomInset: 0 },
);

const stageEl = ref<HTMLElement | null>(null);
const turntableEl = ref<HTMLElement | null>(null);
const mediaEl = ref<HTMLElement | null>(null);

const detectedW = ref(1000);
const detectedH = ref(750);
const natW = computed(() => (props.imageWidth > 0 ? props.imageWidth : detectedW.value));
const natH = computed(() => (props.imageHeight > 0 ? props.imageHeight : detectedH.value));

// The stage element is a fixed layer; its padding only declares the usable area.
const stageBox = ref({ w: 600, h: 450, top: 0, right: 0, bottom: 0, left: 0, barOverlap: 0 });
const barInset = computed(() => Math.max(0, props.bottomInset - stageBox.value.barOverlap));
const stageW = computed(() => Math.max(1, stageBox.value.w - stageBox.value.left - stageBox.value.right));
const stageH = computed(() =>
  Math.max(1, stageBox.value.h - stageBox.value.top - stageBox.value.bottom - barInset.value),
);

const ratioInverted = ref(false);
const interacting = ref(false);
const draggingHandle = ref(false);
const straightening = ref(false);
const animateTransform = ref(false);
const turntableAnimated = ref(false);
const turntableTransform = ref('none');

interface ActiveHandleDragView {
  px: number;
  py: number;
  scaleDrag: number;
  boxX: number;
  boxY: number;
  boxW: number;
  boxH: number;
}
const handleDragView = ref<ActiveHandleDragView | null>(null);

let settleTimer: ReturnType<typeof setTimeout> | null = null;
let turntableTimer: ReturnType<typeof setTimeout> | null = null;
let straightenTimer: ReturnType<typeof setTimeout> | null = null;
let stageRo: ResizeObserver | null = null;
let mediaMo: MutationObserver | null = null;
let activePointerId: number | null = null;
let cleanupDrag: (() => void) | null = null;
let hammer: HammerManager | null = null;
let pinch: { ctx: PinchContext; startZoom: number } | null = null;

const isCropMode = computed(() => props.section === 'crop' && !props.state.comparing);
const dims = computed(() => orientedDims(natW.value, natH.value, props.state.rotate));
const geom = computed(() => effectiveCrop(props.state, dims.value.W, dims.value.H));

const scaleSettled = computed(() => {
  const { w, h } = geom.value;
  if (w <= 0 || h <= 0) return 1;
  return Math.min(stageW.value / w, stageH.value / h);
});

const scaleScreen = computed(() => handleDragView.value?.scaleDrag ?? scaleSettled.value);

const boxRect = computed(() => {
  if (handleDragView.value) {
    return {
      x: handleDragView.value.boxX,
      y: handleDragView.value.boxY,
      w: handleDragView.value.boxW,
      h: handleDragView.value.boxH,
    };
  }
  const s = scaleSettled.value;
  return {
    x: 0,
    y: 0,
    w: geom.value.w * s,
    h: geom.value.h * s,
  };
});

const shift = computed(() => {
  const { top, right, bottom, left } = stageBox.value;
  return { x: (left - right) / 2, y: (top - bottom - barInset.value) / 2 };
});

const shiftStyle = computed(() => ({
  transform: `translate3d(${shift.value.x}px, ${shift.value.y}px, 0)`,
}));

const turntableStyle = computed(() => ({
  transform: turntableTransform.value,
}));

const mediaStyle = computed(() => {
  const w = natW.value;
  const h = natH.value;
  if (props.state.comparing) {
    const s = Math.min(stageW.value / w, stageH.value / h);
    return {
      width: `${w}px`,
      height: `${h}px`,
      marginLeft: `${-w / 2}px`,
      marginTop: `${-h / 2}px`,
      transform: `scale(${s})`,
    };
  }
  const px = handleDragView.value ? handleDragView.value.px : geom.value.px;
  const py = handleDragView.value ? handleDragView.value.py : geom.value.py;
  const { x, y } = boxRect.value;
  const s = scaleScreen.value;
  return {
    width: `${w}px`,
    height: `${h}px`,
    marginLeft: `${-w / 2}px`,
    marginTop: `${-h / 2}px`,
    transform: `translate3d(${x}px, ${y}px, 0) scale(${s}) rotate(${props.state.straighten}deg) translate3d(${-px}px, ${-py}px, 0) scale(${props.state.flipH}, ${props.state.flipV}) rotate(${props.state.rotate}deg)`,
  };
});

function settle(ms = 260) {
  if (settleTimer) clearTimeout(settleTimer);
  animateTransform.value = true;
  settleTimer = setTimeout(() => {
    animateTransform.value = false;
  }, ms);
}

watch(
  () => props.state.straighten,
  () => {
    straightening.value = true;
    if (straightenTimer) clearTimeout(straightenTimer);
    straightenTimer = setTimeout(() => {
      straightening.value = false;
    }, 450);
  },
);

watch(
  () => props.section,
  () => {
    settle();
    nextTick(measureStage);
  },
);

watch(
  () => props.bottomInset,
  (_, prev) => {
    if (prev > 0 && activePointerId === null) settle();
  },
);

function rotateBy(deg: number) {
  const s = props.state;
  const oldSw = boxRect.value.w;
  const dir = deg > 0 ? 1 : -1;
  const next = rotateCrop90(s, dir);

  s.rotate += deg;
  if (s.flipH !== s.flipV) {
    const tmp = s.flipH;
    s.flipH = s.flipV;
    s.flipV = tmp;
  }
  s.cropX = next.cropX;
  s.cropY = next.cropY;
  s.cropW = next.cropW;
  s.cropH = next.cropH;
  ratioInverted.value = !ratioInverted.value;

  const newSh = boxRect.value.h;
  const flipScale = newSh > 0 ? oldSw / newSh : 1;

  if (turntableTimer) clearTimeout(turntableTimer);
  animateTransform.value = false;
  turntableAnimated.value = false;
  turntableTransform.value = `rotate(${-deg}deg) scale(${flipScale})`;

  requestAnimationFrame(() => {
    void turntableEl.value?.offsetWidth;
    turntableAnimated.value = true;
    turntableTransform.value = 'none';
    turntableTimer = setTimeout(() => {
      turntableAnimated.value = false;
    }, 280);
  });
}

function toggleFlipH() {
  const s = props.state;
  s.flipH = (s.flipH * -1) as 1 | -1;
  s.cropX = -s.cropX;
  s.straighten = -s.straighten;
  settle();
}

function toggleFlipV() {
  const s = props.state;
  s.flipV = (s.flipV * -1) as 1 | -1;
  s.cropY = -s.cropY;
  s.straighten = -s.straighten;
  settle();
}

function setRatio(id: string) {
  const s = props.state;
  s.ratio = id;
  ratioInverted.value = false;
  const r = parseRatio(id, dims.value.W, dims.value.H, false);
  if (r !== null) {
    const next = fitAspectRatio(r, geom.value.px, geom.value.py, dims.value.W, dims.value.H, s.straighten);
    s.cropX = next.cropX;
    s.cropY = next.cropY;
    s.cropW = next.cropW;
    s.cropH = next.cropH;
    settle();
  }
}

function resetCrop() {
  const s = props.state;
  s.cropX = 0;
  s.cropY = 0;
  s.cropW = 1;
  s.cropH = 1;
  ratioInverted.value = false;
  settle();
}

function onHandleStart(handle: CropHandle, e: PointerEvent) {
  if (!isCropMode.value || !e.isPrimary || activePointerId !== null) return;
  if (e.pointerType === 'mouse' && e.button !== 0) return;

  if (settleTimer) clearTimeout(settleTimer);
  animateTransform.value = false;
  interacting.value = true;
  draggingHandle.value = true;
  const pointerId = e.pointerId;
  activePointerId = pointerId;

  const g = geom.value;
  const { W, H } = dims.value;
  const rad = (props.state.straighten * Math.PI) / 180;
  const c = Math.cos(rad);
  const s = Math.sin(rad);
  const uc0 = g.px * c - g.py * s;
  const vc0 = g.px * s + g.py * c;

  const ctx: HandleDragContext = {
    handle,
    startClientX: e.clientX,
    startClientY: e.clientY,
    startScale: scaleSettled.value,
    startW: g.w,
    startH: g.h,
    uMin0: uc0 - g.w / 2,
    uMax0: uc0 + g.w / 2,
    vMin0: vc0 - g.h / 2,
    vMax0: vc0 + g.h / 2,
    W,
    H,
    straightenDeg: props.state.straighten,
    ratio: parseRatio(props.state.ratio, W, H, ratioInverted.value),
    stageW: stageW.value,
    stageH: stageH.value,
  };

  let lastClientX = e.clientX;
  let lastClientY = e.clientY;
  let boostZoomX = 1;
  let boostZoomY = 1;
  let rafId: number | null = null;
  let lastRafTime = 0;
  let lastRes: HandleDragResult | null = null;

  const speedFor = (dist: number) => 0.28 + Math.min(1, Math.max(0, dist - 2) / 60) * 0.42;

  const applyDrag = () => {
    const res = solveHandleDrag(ctx, lastClientX, lastClientY, boostZoomX, boostZoomY);
    props.state.cropX = res.cropX;
    props.state.cropY = res.cropY;
    props.state.cropW = res.cropW;
    props.state.cropH = res.cropH;
    handleDragView.value = {
      px: res.px,
      py: res.py,
      scaleDrag: res.scaleDrag,
      boxX: res.boxX,
      boxY: res.boxY,
      boxW: res.boxW,
      boxH: res.boxH,
    };
    lastRes = res;
    return res;
  };

  const stepBoost = (now: number) => {
    const dt = lastRafTime > 0 ? Math.min(0.05, (now - lastRafTime) / 1000) : 1 / 60;
    lastRafTime = now;
    const cur = lastRes ?? applyDrag();
    if (!cur.canExpandX && !cur.canExpandY) {
      rafId = null;
      lastRafTime = 0;
      return;
    }
    if (cur.canExpandX && cur.canExpandY && ctx.ratio !== null) {
      const z = Math.min(cur.maxBoostZoomX, boostZoomX * (1 + speedFor(cur.boostDistX) * dt));
      boostZoomX = z;
      boostZoomY = z;
    } else {
      if (cur.canExpandX) {
        boostZoomX = Math.min(cur.maxBoostZoomX, boostZoomX * (1 + speedFor(cur.boostDistX) * dt));
      }
      if (cur.canExpandY) {
        boostZoomY = Math.min(cur.maxBoostZoomY, boostZoomY * (1 + speedFor(cur.boostDistY) * dt));
      }
    }
    const next = applyDrag();
    if (next.canExpandX || next.canExpandY) {
      rafId = requestAnimationFrame(stepBoost);
    } else {
      rafId = null;
      lastRafTime = 0;
    }
  };

  const onMove = (ev: PointerEvent) => {
    if (ev.pointerId !== pointerId) return;
    lastClientX = ev.clientX;
    lastClientY = ev.clientY;
    const res = applyDrag();
    if ((res.canExpandX || res.canExpandY) && rafId === null) {
      lastRafTime = 0;
      rafId = requestAnimationFrame(stepBoost);
    }
  };

  const onUp = (ev: PointerEvent) => {
    if (ev.pointerId !== pointerId) return;
    cleanup();
    settle(280);
  };

  const cleanup = () => {
    if (rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onUp);
    activePointerId = null;
    handleDragView.value = null;
    interacting.value = false;
    draggingHandle.value = false;
    cleanupDrag = null;
  };

  cleanupDrag = cleanup;
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', onUp);
}

function onPanStart(e: PointerEvent) {
  if (!isCropMode.value || !e.isPrimary || activePointerId !== null) return;
  if (e.pointerType === 'mouse' && e.button !== 0) return;
  e.preventDefault();

  if (settleTimer) clearTimeout(settleTimer);
  animateTransform.value = false;
  interacting.value = true;
  const pointerId = e.pointerId;
  activePointerId = pointerId;

  const startX = e.clientX;
  const startY = e.clientY;
  const startPx = geom.value.px;
  const startPy = geom.value.py;
  const w = geom.value.w;
  const h = geom.value.h;
  const curScale = scaleSettled.value;

  const onMove = (ev: PointerEvent) => {
    if (ev.pointerId !== pointerId) return;
    const dx = ev.clientX - startX;
    const dy = ev.clientY - startY;
    const next = applyPanDelta(
      startPx,
      startPy,
      w,
      h,
      dx,
      dy,
      curScale,
      dims.value.W,
      dims.value.H,
      props.state.straighten,
    );
    props.state.cropX = next.cropX;
    props.state.cropY = next.cropY;
    props.state.cropW = next.cropW;
    props.state.cropH = next.cropH;
  };

  const onUp = (ev: PointerEvent) => {
    if (ev.pointerId !== pointerId) return;
    cleanup();
  };

  const cleanup = () => {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onUp);
    activePointerId = null;
    interacting.value = false;
    cleanupDrag = null;
  };

  cleanupDrag = cleanup;
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', onUp);
}

/** Offset of a client point from the crop box center. */
function boxOffset(p: HammerPoint) {
  const rect = stageEl.value!.getBoundingClientRect();
  return {
    x: p.x - rect.left - rect.width / 2 - shift.value.x,
    y: p.y - rect.top - rect.height / 2 - shift.value.y,
  };
}

function onPinchStart(e: HammerInput) {
  if (!isCropMode.value) return;
  cleanupDrag?.();
  if (settleTimer) clearTimeout(settleTimer);
  animateTransform.value = false;
  interacting.value = true;
  cleanupDrag = onPinchEnd;

  const focal = boxOffset(e.center);
  const g = geom.value;
  pinch = {
    startZoom: e.scale,
    ctx: {
      startFocalX: focal.x,
      startFocalY: focal.y,
      startScale: scaleSettled.value,
      startPx: g.px,
      startPy: g.py,
      startW: g.w,
      startH: g.h,
      W: dims.value.W,
      H: dims.value.H,
      straightenDeg: props.state.straighten,
    },
  };
}

function onPinchMove(e: HammerInput) {
  if (!pinch) return;
  const focal = boxOffset(e.center);
  Object.assign(props.state, solvePinch(pinch.ctx, e.scale / pinch.startZoom, focal.x, focal.y));
}

function onPinchEnd() {
  pinch = null;
  interacting.value = false;
  cleanupDrag = null;
}

async function setupHammer() {
  const { default: Hammer } = await import('hammerjs');
  if (!stageEl.value || hammer) return;

  hammer = new Hammer.Manager(stageEl.value, { inputClass: Hammer.TouchInput, touchAction: 'none' });
  hammer.add(new Hammer.Pinch());
  hammer.on('pinchstart', onPinchStart);
  hammer.on('pinchmove', onPinchMove);
  hammer.on('pinchend pinchcancel', onPinchEnd);
}

function measureStage() {
  const el = stageEl.value;
  if (!el) return;
  const w = el.clientWidth;
  const h = el.clientHeight;
  if (w > 0 && h > 0) {
    const cs = getComputedStyle(el);
    stageBox.value = {
      w,
      h,
      top: parseFloat(cs.paddingTop),
      right: parseFloat(cs.paddingRight),
      bottom: parseFloat(cs.paddingBottom),
      left: parseFloat(cs.paddingLeft),
      barOverlap: parseFloat(cs.getPropertyValue('--me-bar-overlap')) || 0,
    };
  }
}

function detectMediaSize() {
  if (props.imageWidth > 0 && props.imageHeight > 0) return;
  const root = mediaEl.value;
  if (!root) return;
  const canvas = root.querySelector('canvas');
  if (canvas && canvas.style.display !== 'none' && canvas.width > 0 && canvas.height > 0) {
    if (detectedW.value !== canvas.width || detectedH.value !== canvas.height) {
      detectedW.value = canvas.width;
      detectedH.value = canvas.height;
    }
    return;
  }
  const img = root.querySelector('img');
  if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
    if (detectedW.value !== img.naturalWidth || detectedH.value !== img.naturalHeight) {
      detectedW.value = img.naturalWidth;
      detectedH.value = img.naturalHeight;
    }
  }
}

onMounted(() => {
  void setupHammer();
  measureStage();
  detectMediaSize();
  if (window.ResizeObserver && stageEl.value) {
    stageRo = new ResizeObserver(() => measureStage());
    stageRo.observe(stageEl.value);
  } else {
    window.addEventListener('resize', measureStage);
  }
  if (mediaEl.value) {
    mediaEl.value.addEventListener('load', detectMediaSize, true);
    if (window.MutationObserver) {
      mediaMo = new MutationObserver(() => detectMediaSize());
      mediaMo.observe(mediaEl.value, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['width', 'height', 'style', 'src'],
      });
    }
  }
});

onBeforeUnmount(() => {
  cleanupDrag?.();
  hammer?.destroy();
  stageRo?.disconnect();
  mediaMo?.disconnect();
  window.removeEventListener('resize', measureStage);
  mediaEl.value?.removeEventListener('load', detectMediaSize, true);
  if (settleTimer) clearTimeout(settleTimer);
  if (turntableTimer) clearTimeout(turntableTimer);
  if (straightenTimer) clearTimeout(straightenTimer);
});

defineExpose({
  rotateBy,
  toggleFlipH,
  toggleFlipV,
  setRatio,
  resetCrop,
  settle,
});
</script>
