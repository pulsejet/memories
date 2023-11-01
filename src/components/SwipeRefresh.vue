<template>
  <div @touchstart.passive="touchstart" @touchmove.passive="touchmove" @touchend.passive="touchend">
    <div v-if="on && progress" class="swipe-progress" :style="{ background: gradient }"></div>
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
  }),

  computed: {
    gradient() {
      const start = 50 - this.progress / 2;
      const end = 50 + this.progress / 2;
      const out = 'transparent';
      const progress = 'var(--color-primary)';
      return `linear-gradient(to right, ${out} ${start}%, ${progress} ${start}%, ${progress} ${end}%, ${out} ${end}%)`;
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

  html.native & {
    top: 2px;
  }
}
</style>
