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

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';
import Timeline from './Timeline.vue';
const MapSplitMatter = defineAsyncComponent(() => import('./top-matter/MapSplitMatter.vue'));
import Hammer from 'hammerjs';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'SplitTimeline',

  components: {
    Timeline,
  },

  data: () => ({
    pointerDown: false,
    primaryPos: 0,
    containerSize: 0,
    mobileOpen: 1,
    hammer: null as HammerManager | null,
    photoCount: 0,
  }),

  computed: {
    refs() {
      return this.$refs as {
        container?: HTMLDivElement;
        primary?: HTMLDivElement;
        separator?: HTMLDivElement;
        timelineHeader?: HTMLDivElement;
      };
    },

    primary() {
      switch (this.$route.name) {
        case _m.routes.Map.name:
          return MapSplitMatter;
        default:
          return null;
      }
    },

    headerClass() {
      switch (this.mobileOpen) {
        case 0:
          return 'm-zero';
        case 1:
          return 'm-one';
        case 2:
          return 'm-two';
      }
    },
  },

  mounted() {
    // Set up hammerjs hooks
    this.hammer = new Hammer(this.refs.timelineHeader!);
    this.hammer.get('swipe').set({
      direction: Hammer.DIRECTION_VERTICAL,
      threshold: 3,
    });
    this.hammer.on('swipeup', this.mobileSwipeUp);
    this.hammer.on('swipedown', this.mobileSwipeDown);
  },

  beforeDestroy() {
    this.pointerUp();
    this.hammer?.destroy();
  },

  methods: {
    isVertical() {
      return false; // for future
    },

    sepDown(event: PointerEvent) {
      this.pointerDown = true;

      // Get position of primary element
      const rect = this.refs.primary!.getBoundingClientRect();
      this.primaryPos = this.isVertical() ? rect.top : rect.left;

      // Get size of container element
      const cRect = this.refs.container!.getBoundingClientRect();
      this.containerSize = this.isVertical() ? cRect.height : cRect.width;

      // Let touch handle itself
      if (event.pointerType === 'touch') return;

      // Otherwise, handle pointer events on document
      document.addEventListener('pointermove', this.documentPointerMove);
      document.addEventListener('pointerup', this.pointerUp);

      // Prevent text selection
      event.preventDefault();
      event.stopPropagation();
    },

    sepTouchMove(event: TouchEvent) {
      if (!this.pointerDown) return;
      this.setFlexBasis(event.touches[0]);
    },

    documentPointerMove(event: PointerEvent) {
      if (!this.pointerDown || !event.buttons) return this.pointerUp();
      this.setFlexBasis(event);
    },

    pointerUp() {
      // Get rid of listeners on document quickly
      this.pointerDown = false;
      document.removeEventListener('pointermove', this.documentPointerMove);
      document.removeEventListener('pointerup', this.pointerUp);
      utils.bus.emit('memories:window:resize', null);
    },

    setFlexBasis(pos: { clientX: number; clientY: number }) {
      const ref = this.isVertical() ? pos.clientY : pos.clientX;
      const newSize = Math.max(ref - this.primaryPos, 50);
      const pctSize = (newSize / this.containerSize) * 100;
      this.refs.primary!.style.flexBasis = `${pctSize}%`;
    },

    daysLoaded({ count }: { count: number }) {
      this.photoCount = count;
    },

    async mobileSwipeUp() {
      this.mobileOpen = Math.min(this.mobileOpen + 1, 2);

      // When swiping up, immediately emit a resize event
      // so that we can prepare in advance for showing more photos
      // on the timeline
      await this.$nextTick();
      utils.bus.emit('memories:window:resize', null);
    },

    async mobileSwipeDown() {
      this.mobileOpen = Math.max(this.mobileOpen - 1, 0);

      // When swiping down, wait for the animation to end, so that
      // we don't hide the lower half of the timeline before the animation
      // ends. Note that this is necesary: the height of the timeline inner
      // div is also animated to the smaller size.
      await new Promise((resolve) => setTimeout(resolve, 300));
      utils.bus.emit('memories:window:resize', null);
    },
  },
});
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
          background-color: var(--color-text-light);
          z-index: 1;
          opacity: 0.75;
          pointer-events: none;
        }

        > .title {
          padding-top: 22px;
        }
      }

      :deep .empty-content {
        margin-top: 20%; // was 20vh
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
