<template>
  <div ref="root">
    <div
      v-if="showRefreshProgress"
      class="swipe-progress"
      :class="{ animate: refreshAnimate, wasSwiped: refreshWasSwiped }"
      :style="{ '--swipe-progress': refreshProgress }"
    ></div>
    <slot></slot>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, useTemplateRef } from 'vue';

const REFRESH_SWIPE_PX = 250;
// Pan must start moving before it counts, so parked touches never show progress.
const REFRESH_PAN_THRESHOLD_PX = 10;

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

const root = useTemplateRef<HTMLElement>('root');
let hammer: HammerManager | null = null;

/** Is active interaction */
const refreshActive = ref(false);
/** Cumulative pan displacement since panstart */
const refreshDeltaX = ref(0);
const refreshDeltaY = ref(0);
/** Percentage progress to show in swiping */
const refreshProgress = ref(0);
/** Next update frame reference */
const refreshUpdateFrame = ref(0);

// Loading animation state
const refreshLoading = ref(false);
const refreshAnimate = ref(false);
const refreshWasSwiped = ref(true);
const refreshFirstCycle = ref(0);

onMounted(() => {
  refreshAnimate.value = refreshLoading.value; // start if needed
  props.signal?.addEventListener('abort', reset, { once: true });
  void setupHammer();
});

onBeforeUnmount(() => {
  props.signal?.removeEventListener('abort', reset);
  hammer?.destroy();
  hammer = null;
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

watch(refreshLoading, () => {
  refreshWasSwiped.value = refreshProgress.value >= 100;
  if (!refreshWasSwiped.value) {
    // The loading animation was triggered from elsewhere
    // let it continue normally
    refreshAnimate.value = refreshLoading.value;
    return;
  }

  // Let the animation run for at least half cycle
  // if the user pulled down, so we provide good feedback
  // that something actually happened
  if (refreshLoading.value) {
    if (!refreshAnimate.value) {
      refreshFirstCycle.value = window.setTimeout(() => {
        refreshFirstCycle.value = 0;
        refreshAnimate.value = refreshLoading.value;
      }, 750);
    }
    refreshAnimate.value = refreshLoading.value;
  } else {
    if (!refreshFirstCycle.value) {
      refreshAnimate.value = refreshLoading.value;
    }
  }
});

const showRefreshProgress = computed(() => {
  return Boolean((refreshActive.value && refreshProgress.value) || refreshAnimate.value);
});

/** Watch vertical pans without stealing scroll: TouchInput survives
 * the pointercancel that kills PointerEventInput during native scroll. */
async function setupHammer() {
  if (!root.value || hammer) return;
  const { default: Hammer } = await import('hammerjs');
  if (!root.value || hammer) return;

  hammer = new Hammer(root.value, {
    touchAction: 'auto',
    inputClass: Hammer.TouchInput,
  });
  hammer.get('pan').set({
    direction: Hammer.DIRECTION_VERTICAL,
    threshold: REFRESH_PAN_THRESHOLD_PX,
  });
  hammer.on('panstart', onPanStart);
  hammer.on('panmove', onPanMove);
  hammer.on('panend pancancel', onPanEnd);
}

function reset() {
  // Clear events
  window.cancelAnimationFrame(refreshUpdateFrame.value);
  window.clearTimeout(refreshFirstCycle.value);

  // Reset state
  refreshActive.value = false;
  refreshProgress.value = 0;
  refreshUpdateFrame.value = 0;
  refreshLoading.value = false;
  refreshAnimate.value = false;
  refreshWasSwiped.value = true;
  refreshFirstCycle.value = 0;
}

/** Start gesture; ignores anything outside the matched scroll area */
function onPanStart(e: HammerInput) {
  if (!props.allowSwipe) return;
  if (e.maxPointers > 1) return;

  // Check if top element matches selector
  if (props.match && !e.target.closest(props.match)) return;

  // Start swipe action
  refreshDeltaX.value = 0;
  refreshDeltaY.value = 0;
  refreshProgress.value = 0;
  refreshActive.value = true;
}

/** Execute gesture; aborts on multi-touch or horizontal dominance */
function onPanMove(e: HammerInput) {
  if (!props.allowSwipe || !refreshActive.value) return;

  // Ignore multi-touch gestures (e.g. pinch zoom)
  if (e.maxPointers > 1) {
    return reset();
  }

  // Get the cumulative displacement
  refreshDeltaX.value = e.deltaX;
  refreshDeltaY.value = e.deltaY;

  // Abort on mostly-horizontal gestures (e.g. swipe right)
  if (Math.abs(refreshDeltaX.value) > Math.abs(refreshDeltaY.value)) {
    return reset();
  }

  // Update progress only once per frame
  refreshUpdateFrame.value ||= window.requestAnimationFrame(async () => {
    refreshUpdateFrame.value = 0;
    if (!refreshActive.value) return;

    // Re-check horizontal dominance with latest coordinates
    if (Math.abs(refreshDeltaX.value) > Math.abs(refreshDeltaY.value)) {
      return reset();
    }

    // Compute percentage of swipe
    const delta = refreshDeltaY.value / REFRESH_SWIPE_PX;
    refreshProgress.value = Math.min(Math.max(0, delta * 100), 100);

    // Execute action on threshold
    if (refreshProgress.value >= 100) {
      refreshActive.value = false;
      const signal = props.signal;
      try {
        refreshLoading.value = true;
        await props.refresh();
      } finally {
        if (signal !== props.signal || !signal?.aborted) {
          refreshLoading.value = false;
        }
      }
    }
  });
}

/** End gesture */
function onPanEnd() {
  window.cancelAnimationFrame(refreshUpdateFrame.value);
  refreshUpdateFrame.value = 0;
  refreshActive.value = false;
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
      var(--color-primary) calc(var(--swipe-progress) * 1%),
      transparent calc(var(--swipe-progress) * 1%),
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
