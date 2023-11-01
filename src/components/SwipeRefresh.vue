<template>
  <div @touchstart.passive="touchstart" @touchmove.passive="touchmove" @touchend.passive="touchend">
    <div v-show="show" class="swipe-progress" :style="{ background: gradient }" :class="{ animate }"></div>
    <slot></slot>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const SWIPE_PX = 250;

export default defineComponent({
  name: 'SwipeRefresh',

  props: {
    allowSwipe: {
      type: Boolean,
      default: true,
    },

    loading: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    refresh: () => true,
  },

  data: () => ({
    on: false,
    start: 0,
    end: 0,
    updateFrame: 0,
    progress: 0,
    animate: false,
    firstcycle: 0,
  }),

  mounted() {
    this.animate = this.loading; // start if needed
  },

  watch: {
    loading() {
      if (this.loading) {
        if (!this.animate) {
          // Let the animation run for at least half cycle
          this.firstcycle = window.setTimeout(() => {
            this.firstcycle = 0;
            this.animate = this.loading;
          }, 750);
        }
        this.animate = this.loading;
      } else {
        if (!this.firstcycle) {
          console.log('stop');
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
      if (!this.allowSwipe) return;
      const touch = event.touches[0];
      this.end = touch.clientY;

      // Update progress only once per frame
      this.updateFrame ||= requestAnimationFrame(() => {
        this.updateFrame = 0;

        // Compute percentage of swipe
        const delta = (this.end - this.start) / SWIPE_PX;
        this.progress = Math.min(Math.max(0, delta * 100), 100);

        // Execute action on threshold
        if (this.progress >= 100) {
          this.on = false;
          this.$emit('refresh');
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
  z-index: 100;
  top: 0;
  width: 100%;
  height: 3px;
  pointer-events: none;

  html.native & {
    top: 2px;
  }

  &.animate {
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
    background-position: center;

    @keyframes swipe-loading {
      0% {
        background-image: $progress-inside;
        background-size: 100% 100%;
      }
      49.99% {
        background-image: $progress-inside;
        background-size: 12000% 12000%;
      }
      50% {
        background-image: $progress-outside;
        background-size: 100% 100%;
      }
      100% {
        background-image: $progress-outside;
        background-size: 12000% 12000%;
      }
    }
  }
}
</style>
