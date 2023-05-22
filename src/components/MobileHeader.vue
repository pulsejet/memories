<template>
  <div
    id="mobile-header"
    class="timeline-scroller-gap"
    :class="{
      visible: !isScrollDown,
    }"
  >
    <div class="logo">
      <XImg :src="nextcloudsvg" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { subscribe, unsubscribe } from '@nextcloud/event-bus';
import nextcloudsvg from '../assets/nextcloud.svg';

export default defineComponent({
  name: 'MobileHeader',

  data: () => ({
    isScrollDown: false,
    nextcloudsvg,
  }),

  mounted() {
    subscribe('memories.recycler.scroll', this.onScroll);
  },

  beforeDestroy() {
    unsubscribe('memories.recycler.scroll', this.onScroll);
  },

  methods: {
    onScroll({ current, previous }: { current: number; previous: number }) {
      this.isScrollDown = (this.isScrollDown && previous - current < 40) || current - previous > 40; // momentum scroll
    },
  },
});
</script>

<style lang="scss">
.mobile-header-top-gap {
  display: none;
  .has-mobile-header & {
    display: block;
    height: 45px;
  }
}
</style>

<style lang="scss" scoped>
#mobile-header {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  z-index: 10;
  height: 50px;
  transform: translateY(-55px);
  transition: transform 0.3s ease-in-out;
  background-color: var(--color-main-background);
  color: var(--color-primary);

  &.visible {
    transform: translateY(0);
  }

  .logo {
    width: 62px;
    position: absolute;
    top: 60%;
    left: 50%;
    transform: translate(-50%, -50%);
  }
}
</style>
