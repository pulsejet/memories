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
        <XImg v-if="logo" :src="logo" :svg-tag="true" />
      </a>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import nextcloudsvg from '../assets/nextcloud.svg';

import * as utils from '../services/utils';

export default defineComponent({
  name: 'MobileHeader',

  data: () => ({
    isScrollDown: false,
    logo: null as string | null,
  }),

  computed: {
    homeUrl(): string {
      return generateUrl('/');
    },
  },

  mounted() {
    utils.bus.on('memories.recycler.scroll', this.onScroll);
    this.getLogo();
  },

  beforeDestroy() {
    utils.bus.off('memories.recycler.scroll', this.onScroll);
  },

  methods: {
    onScroll({ current, previous }: utils.BusEvent['memories.recycler.scroll']) {
      this.isScrollDown = (this.isScrollDown && previous - current < 40) || current - previous > 40; // momentum scroll
    },

    async getLogo() {
      // try to get the logo
      try {
        const style = getComputedStyle(document.body);
        const override = style.getPropertyValue('--image-logoheader') || style.getPropertyValue('--image-logo');
        if (override) {
          // Extract URL from CSS url
          const url = override.match(/url\(["']?([^"']*)["']?\)/i)?.[1];
          if (!url) throw new Error('No URL found');

          // Fetch image
          const blob = (await axios.get(url, { responseType: 'blob' })).data;
          console.log('Loaded logo', blob);

          // Convert to data URI and pass to logo
          const reader = new FileReader();
          reader.readAsDataURL(blob);
          this.logo = await new Promise<string>((resolve, reject) => {
            reader.onloadend = () => resolve(reader.result as string);
            reader.onerror = reject;
            reader.onabort = reject;
          });

          return;
        }
      } catch (e) {
        // Go to fallback
        console.warn('Could not load logo', e);
      }

      // Fallback to default
      this.logo = nextcloudsvg;
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
    height: 90%;
    position: absolute;
    top: 10%;
    left: 50%;
    transform: translateX(-50%);

    > a {
      :deep svg {
        color: var(--color-primary) !important;
      }

      > * {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
    }
  }
}
</style>
