<template>
  <div
    id="mobile-header"
    class="timeline-scroller-gap"
    :class="{
      visible: !isScrollDown,
    }"
  >
    <div class="logo">
      <a :href="homeUrl">
        <XImg :src="nextcloudsvg" :svg-tag="true" />
      </a>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { generateUrl } from '@nextcloud/router';
import nextcloudsvg from '../assets/nextcloud.svg';

import * as utils from '../services/utils';

export default defineComponent({
  name: 'MobileHeader',

  data: () => ({
    isScrollDown: false,
    nextcloudsvg,
  }),

  computed: {
    homeUrl(): string {
      return generateUrl('/');
    },
  },

  mounted() {
    utils.bus.on('memories.recycler.scroll', this.onScroll);
  },

  beforeDestroy() {
    utils.bus.off('memories.recycler.scroll', this.onScroll);
  },

  methods: {
    onScroll({ current, previous }: utils.BusEvent['memories.recycler.scroll']) {
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
  contain: strict;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  z-index: 10;
  height: 50px;
  transform: translateY(-55px);
  transition: transform 0.3s ease-in-out;
  background-color: var(--color-main-background);

  &.visible {
    transform: translateY(0);
  }

  .logo {
    width: 62px;
    position: absolute;
    top: 60%;
    left: 50%;
    transform: translate(-50%, -50%);

    > a {
      color: var(--color-primary);
    }
  }
}
</style>
