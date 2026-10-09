<template>
  <div @touchstart.passive="touchstart" @touchmove.passive="touchmove" @touchend.passive="touchend">
    <div v-show="show" class="swipe-progress" :class="{ animate, wasSwiped }"></div>
    <slot></slot>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const SWIPE_PX = 250;

const props = withDefaults(
  defineProps<{
    /** Callback to execute when the user swipes down */
    refresh: () => Promise<any>;

    /** Whether to allow the swipe action */
    allowSwipe?: boolean;

    /**
     * AbortSignal for the current timeline generation.
     * If aborted, the swipe action is reset.
     */
    signal?: AbortSignal;

    /**
     * An ancestor element of the touch action
     * target must match this query selector to be
     * eligible for the swipe action.
     */
    match?: string;
  }>(),
  {
    allowSwipe: true,
    match: '',
  },
);

/** Is active interaction */
const on = ref(false);
/** Start touch Y coordinate */
const startY = ref(0);
/** End touch Y coordinate */
const endY = ref(0);
/** Start touch X coordinate */
const startX = ref(0);
/** End touch X coordinate */
const endX = ref(0);
/** Percentage progress to show in swiping */
const progress = ref(0);
/** Next update frame reference */
const updateFrame = ref(0);

// Loading animation state
const loading = ref(false);
const animate = ref(false);
const wasSwiped = ref(true);
const firstcycle = ref(0);

onMounted(() => {
  animate.value = loading.value; // start if needed
  props.signal?.addEventListener('abort', reset, { once: true });
});

onBeforeUnmount(() => {
  props.signal?.removeEventListener('abort', reset);
  reset();
});

// A new signal means a new parent generation: drop any in-progress
// gesture and progress tied to the old one, so stale coordinates
// can't trigger a refresh or leave the progress bar stuck.
watch(
  () => props.signal,
  (signal, prev) => {
    prev?.removeEventListener('abort', reset);
    signal?.addEventListener('abort', reset, { once: true });
    reset();
  },
);

watch(loading, () => {
  wasSwiped.value = progress.value >= 100;
  if (!wasSwiped.value) {
    // The loading animation was triggered from elsewhere
    // let it continue normally
    animate.value = loading.value;
    return;
  }

  // Let the animation run for at least half cycle
  // if the user pulled down, so we provide good feedback
  // that something actually happened
  if (loading.value) {
    if (!animate.value) {
      firstcycle.value = window.setTimeout(() => {
        firstcycle.value = 0;
        animate.value = loading.value;
      }, 750);
    }
    animate.value = loading.value;
  } else {
    if (!firstcycle.value) {
      animate.value = loading.value;
    }
  }
});

const show = computed(() => {
  return (on.value && progress.value) || animate.value;
});

function reset() {
  // Clear events
  window.cancelAnimationFrame(updateFrame.value);
  window.clearTimeout(firstcycle.value);

  // Reset state
  on.value = false;
  progress.value = 0;
  updateFrame.value = 0;
  loading.value = false;
  animate.value = false;
  wasSwiped.value = true;
  firstcycle.value = 0;
}

/** Start gesture on container (passive) */
function touchstart(event: TouchEvent) {
  if (!props.allowSwipe) return;
  if (event.touches.length !== 1) return;
  const touch = event.touches[0];

  // Check if top element matches selector
  if (props.match && !(<HTMLElement>touch.target).closest(props.match)) return;

  // Start swipe action
  endY.value = startY.value = touch.clientY;
  endX.value = startX.value = touch.clientX;
  progress.value = 0;
  on.value = true;
}

/** Execute gesture on container (passive) */
function touchmove(event: TouchEvent) {
  if (!props.allowSwipe || !on.value) return;

  // Ignore multi-touch gestures (e.g. pinch zoom)
  if (event.touches.length !== 1) {
    return reset();
  }

  // Get the touch coordinates
  const touch = event.touches[0];
  endY.value = touch.clientY;
  endX.value = touch.clientX;

  // Abort on mostly-horizontal gestures (e.g. swipe right)
  if (Math.abs(endX.value - startX.value) > Math.abs(endY.value - startY.value)) {
    return reset();
  }

  // Update progress only once per frame
  updateFrame.value ||= window.requestAnimationFrame(async () => {
    updateFrame.value = 0;
    if (!on.value) return;

    // Re-check horizontal dominance with latest coordinates
    if (Math.abs(endX.value - startX.value) > Math.abs(endY.value - startY.value)) {
      return reset();
    }

    // Compute percentage of swipe
    const delta = (endY.value - startY.value) / SWIPE_PX;
    progress.value = Math.min(Math.max(0, delta * 100), 100);

    // Execute action on threshold
    if (progress.value >= 100) {
      on.value = false;
      const signal = props.signal;
      try {
        loading.value = true;
        await props.refresh();
      } finally {
        if (signal !== props.signal || !signal?.aborted) {
          loading.value = false;
        }
      }
    }
  });
}

/** End gesture on container (passive) */
function touchend(event: TouchEvent) {
  on.value = false;
}
</script>

<style lang="scss" scoped>
.swipe-progress {
  position: absolute;
  z-index: 400; // above selection manager
  top: 0;
  width: 100%;
  height: 3px;
  pointer-events: none;

  &:not(.animate) {
    background: radial-gradient(
      circle at center,
      var(--color-primary) 0,
      var(--color-primary) calc(v-bind(progress) * 1%),
      transparent calc(v-bind(progress) * 1%),
      transparent 100%
    );
  }

  &.animate {
    background-position: center;
    $progress-inside: radial-gradient(
      circle at center,
      transparent 0%,
      transparent 1%,
      var(--color-primary) 1%,
      var(--color-primary) 100%
    );
    $progress-outside: radial-gradient(
      circle at center,
      var(--color-primary) 0%,
      var(--color-primary) 1%,
      transparent 1%,
      transparent 100%
    );

    animation: swipe-loading 1.5s ease infinite;
    &.wasSwiped {
      animation-delay: -0.75s;
    }

    @keyframes swipe-loading {
      0% {
        background-image: $progress-outside;
        background-size: 100% 100%;
      }
      49.99% {
        background-image: $progress-outside;
        background-size: 11000% 11000%;
      }
      50% {
        background-image: $progress-inside;
        background-size: 100% 100%;
      }
      100% {
        background-image: $progress-inside;
        background-size: 11000% 11000%;
      }
    }
  }
}
</style>
