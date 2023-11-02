<template>
  <div @touchstart.passive="touchstart" @touchmove.passive="touchmove" @touchend.passive="touchend">
    <div v-show="show" class="swipe-progress" :style="{ background: gradient }" :class="{ animate, wasSwiped }"></div>
    <slot></slot>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

const SWIPE_PX = 250;

export default defineComponent({
  name: 'SwipeRefresh',

  props: {
    refresh: {
      type: Function as PropType<() => Promise<any>>,
      required: true,
    },

    allowSwipe: {
      type: Boolean,
      default: true,
    },

    state: {
      type: Number,
      default: Math.random(),
    },
  },

  data: () => ({
    /** Is active interaction */
    on: false,
    /** Start touch Y coordinate */
    start: 0,
    /** End touch Y coordinate */
    end: 0,
    /** Percentage progress to show in swiping */
    progress: 0,
    /** Next update frame reference */
    updateFrame: 0,

    // Loading animation state
    loading: false,
    animate: false,
    wasSwiped: true,
    firstcycle: 0,
  }),

  emits: [],

  mounted() {
    this.animate = this.loading; // start if needed
  },

  beforeDestroy() {
    this.reset();
  },

  watch: {
    state() {
      this.reset();
    },

    loading() {
      this.wasSwiped = this.progress >= 100;
      if (!this.wasSwiped) {
        // The loading animation was triggered from elsewhere
        // let it continue normally
        this.animate = this.loading;
        return;
      }

      // Let the animation run for at least half cycle
      // if the user pulled down, so we provide good feedback
      // that something actually happened
      if (this.loading) {
        if (!this.animate) {
          this.firstcycle = window.setTimeout(() => {
            this.firstcycle = 0;
            this.animate = this.loading;
          }, 750);
        }
        this.animate = this.loading;
      } else {
        if (!this.firstcycle) {
          this.animate = this.loading;
        }
      }
    },
  },

  computed: {
    show() {
      return (this.on && this.progress) || this.animate;
    },

    gradient() {
      if (this.animate) {
        // CSS animation below
        return undefined;
      }

      // Pull down progress
      const p = this.progress;
      const outer = 'transparent';
      const inner = 'var(--color-primary)';
      return `radial-gradient(circle at center, ${inner} 0, ${inner} ${p}%, ${outer} ${p}%, ${outer} 100%)`;
    },
  },

  methods: {
    reset() {
      // Clear events
      window.cancelAnimationFrame(this.updateFrame);
      window.clearTimeout(this.firstcycle);

      // Reset state
      this.on = false;
      this.progress = 0;
      this.updateFrame = 0;
      this.loading = false;
      this.animate = false;
      this.wasSwiped = true;
      this.firstcycle = 0;
    },

    /** Start gesture on container (passive) */
    touchstart(event: TouchEvent) {
      if (!this.allowSwipe) return;
      const touch = event.touches[0];
      this.end = this.start = touch.clientY;
      this.progress = 0;
      this.on = true;
    },

    /** Execute gesture on container (passive) */
    touchmove(event: TouchEvent) {
      if (!this.allowSwipe || !this.on) return;
      const touch = event.touches[0];
      this.end = touch.clientY;

      // Update progress only once per frame
      this.updateFrame ||= window.requestAnimationFrame(async () => {
        this.updateFrame = 0;

        // Compute percentage of swipe
        const delta = (this.end - this.start) / SWIPE_PX;
        this.progress = Math.min(Math.max(0, delta * 100), 100);

        // Execute action on threshold
        if (this.progress >= 100) {
          this.on = false;
          const state = this.state;
          try {
            this.loading = true;
            await this.refresh();
          } finally {
            if (this.state === state) {
              this.loading = false;
            }
          }
        }
      });
    },

    /** End gesture on container (passive) */
    touchend(event: TouchEvent) {
      this.on = false;
    },
  },
});
</script>

<style lang="scss" scoped>
.swipe-progress {
  position: absolute;
  z-index: 400; // above selection manager
  top: 0;
  width: 100%;
  height: 3px;
  pointer-events: none;

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
