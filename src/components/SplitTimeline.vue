<template>
  <div class="split-container" ref="container" :class="headerClass">
    <div class="primary" ref="primary">
      <component :is="primary" />
    </div>

    <div
      class="separator"
      ref="separator"
      @pointerdown="sepDown"
      @touchmove.passive="sepTouchMove"
      @touchend.passive="pointerUp"
      @touchcancel.passive="pointerUp"
    ></div>

    <div class="timeline">
      <div class="timeline-header" ref="timelineHeader">
        <div class="swiper"></div>
        <div class="title">
          {{ t('memories', '{photoCount} photos', { photoCount }) }}
        </div>
      </div>
      <div class="timeline-inner">
        <Timeline @daysLoaded="daysLoaded" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, useTemplateRef, defineAsyncComponent, markRaw } from 'vue';
import { useRoute } from 'vue-router';

import { t } from '@services/l10n';

import Timeline from '@components/Timeline.vue';
const MapSplitMatter = defineAsyncComponent(() => import('@components/top-matter/MapSplitMatter.vue'));

import Hammer from 'hammerjs';

const route = useRoute();

const containerRef = useTemplateRef<HTMLDivElement>('container');
const primaryRef = useTemplateRef<HTMLDivElement>('primary');
const timelineHeaderRef = useTemplateRef<HTMLDivElement>('timelineHeader');

const pointerDown = ref(false);
const primaryPos = ref(0);
const containerSize = ref(0);
const mobileOpen = ref(1);
const photoCount = ref(0);
let hammer: HammerManager | null = null;

const primary = computed(() => {
  switch (route.name) {
    case _m.routes.Map.name:
      return markRaw(MapSplitMatter);
    default:
      return null;
  }
});

const headerClass = computed(() => {
  switch (mobileOpen.value) {
    case 0:
      return 'm-zero';
    case 1:
      return 'm-one';
    case 2:
      return 'm-two';
  }
});

onMounted(() => {
  // Set up hammerjs hooks
  hammer = markRaw(new Hammer(timelineHeaderRef.value!));
  hammer.get('swipe').set({
    direction: Hammer.DIRECTION_VERTICAL,
    threshold: 3,
  });
  hammer.on('swipeup', mobileSwipeUp);
  hammer.on('swipedown', mobileSwipeDown);
});

onBeforeUnmount(() => {
  pointerUp();
  hammer?.destroy();
});

function isVertical() {
  return false; // for future
}

function sepDown(event: PointerEvent) {
  pointerDown.value = true;

  // Get position of primary element
  const rect = primaryRef.value!.getBoundingClientRect();
  primaryPos.value = isVertical() ? rect.top : rect.left;

  // Get size of container element
  const cRect = containerRef.value!.getBoundingClientRect();
  containerSize.value = isVertical() ? cRect.height : cRect.width;

  // Let touch handle itself
  if (event.pointerType === 'touch') return;

  // Otherwise, handle pointer events on document
  document.addEventListener('pointermove', documentPointerMove);
  document.addEventListener('pointerup', pointerUp);

  // Prevent text selection
  event.preventDefault();
  event.stopPropagation();
}

function sepTouchMove(event: TouchEvent) {
  if (!pointerDown.value) return;
  setFlexBasis(event.touches[0]);
}

function documentPointerMove(event: PointerEvent) {
  if (!pointerDown.value || !event.buttons) return pointerUp();
  setFlexBasis(event);
}

function pointerUp() {
  // Get rid of listeners on document quickly
  pointerDown.value = false;
  document.removeEventListener('pointermove', documentPointerMove);
  document.removeEventListener('pointerup', pointerUp);
}

function setFlexBasis(pos: { clientX: number; clientY: number }) {
  const ref = isVertical() ? pos.clientY : pos.clientX;
  const newSize = Math.max(ref - primaryPos.value, 50);
  const pctSize = (newSize / containerSize.value) * 100;
  primaryRef.value!.style.flexBasis = `${pctSize}%`;
}

function daysLoaded({ count }: { count: number }) {
  photoCount.value = count;
}

async function mobileSwipeUp() {
  mobileOpen.value = Math.min(mobileOpen.value + 1, 2);
}

async function mobileSwipeDown() {
  mobileOpen.value = Math.max(mobileOpen.value - 1, 0);
}
</script>

<style lang="scss" scoped>
.split-container {
  width: 100%;
  height: 100%;
  display: flex;
  overflow: hidden;
  position: relative;

  > div {
    height: 100%;
    max-height: 100%;
  }

  > .primary {
    flex-basis: 60%;
    flex-shrink: 0;
  }

  > .timeline {
    flex-basis: auto;
    flex-grow: 1;
    padding-left: 8px;
    overflow: hidden;
    display: flex;
    flex-direction: column;

    > .timeline-header {
      position: relative;
      display: block;
      height: 50px;
      flex-shrink: 0;
      flex-grow: 0;
      border-bottom: 1px solid var(--color-border-dark);

      .swiper {
        display: none;
      }

      > .title {
        width: 100%;
        height: 100%;
        text-align: center;
        font-weight: 500;
        padding-top: 12px;
      }
    }

    > .timeline-inner {
      flex-grow: 1;
      overflow: hidden;
    }
  }

  > .separator {
    flex-grow: 0;
    flex-shrink: 0;
    width: 5px;
    background-color: gray;
    opacity: 0.1;
    cursor: col-resize;
    margin: 0 0 0 auto;
    transition:
      opacity 0.4s ease-out,
      background-color 0.4s ease-out;
  }

  > .separator:hover {
    opacity: 0.4;
    background-color: var(--color-primary);
  }
}

@media (max-width: 768px) {
  $headerHeight: 58px;

  /**
   * On mobile the layout works completely differently
   * Both components are full-height, and the separatator
   * brings up the timeline to cover up the primary component
   * fully when dragged up.
   */
  .split-container {
    display: block;

    > div {
      position: absolute;
      width: 100%;
      background-color: var(--color-main-background);
    }

    .primary {
      height: 50%;
      will-change: height;
    }

    > .separator {
      display: none;
    }

    > .timeline {
      height: 50%;
      padding-left: 0;

      // Note: you can't use transforms to animate the top
      // because it causes the viewer to be rendered incorrectly
      transition:
        top 0.2s ease,
        height 0.2s ease;

      > .timeline-header {
        height: $headerHeight;

        > .swiper {
          display: block;
          position: absolute;
          left: 50%;
          top: 0;
          transform: translate(-50%, 9px);
          width: 22px;
          height: 4px;
          border-radius: 40px;
          background-color: var(--color-text-maxcontrast);
          z-index: 1;
          opacity: 0.75;
          pointer-events: none;
        }

        > .title {
          padding-top: 22px;
        }
      }
    }

    &.m-zero > .timeline {
      top: calc(100% - $headerHeight); // show attribution
    }
    &.m-zero > .primary {
      height: calc(100% - $headerHeight); // show full map
    }

    &.m-one > .timeline {
      top: 50%; // show half map
    }

    &.m-two > .timeline {
      top: 0%;
      height: 100%;
    }
  }
}
</style>
