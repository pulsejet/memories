<template>
  <div
    id="mobile-header"
    class="timeline-scroller-gap"
    :class="{
      visible,
    }"
  >
    <div class="logo">
      <a :href="homeUrl"><XImg :src="banner" :svg-tag="true" /></a>
    </div>

    <div class="actions">
      <UploadMenuItem />
      <SearchbarMenuItem />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { generateUrl } from '@nextcloud/router';

import UploadMenuItem from '@components/header/UploadMenuItem.vue';
import SearchbarMenuItem from '@components/header/SearchbarMenuItem.vue';
import XImg from '@components/frame/XImg.vue';

import * as utils from '@services/utils/common';

import banner from '@assets/banner.svg';

const visible = ref(true);
const homeUrl = generateUrl('/');
let wasScrollerInteracting = false;

utils.useBus('memories.recycler.scroll', ({ current, previous, scrollerInteracting }) => {
  if (current <= 40) {
    visible.value = true; // top of page
    wasScrollerInteracting = false;
  } else if (scrollerInteracting || wasScrollerInteracting) {
    visible.value = false; // dragging scroller
    wasScrollerInteracting = scrollerInteracting;
  } else {
    const delta = current - previous;
    visible.value = (visible.value || delta <= -40) && delta <= 40; // momentum scroll
  }
});
</script>

<style lang="scss">
.mobile-header-top-gap {
  display: block;
  height: 45px;
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
  background-color: var(--color-main-background);

  transform: translateY(-55px);
  transition: transform 0.3s ease-in-out;
  &.visible {
    transform: translateY(0);
  }

  display: flex;
  flex-direction: row;

  > .logo {
    height: 100%;
    flex: 1;

    > a {
      display: inline-block;
      height: 100%;
      padding: 12px 12px;

      padding: 8px 64px; // desktop
      @media (max-width: 768px) {
        padding: 8px 30px; // tablet
      }
      @media (max-width: 600px) {
        padding: 12px 12px; // mobile
      }

      :deep(svg) {
        color: var(--color-primary) !important;
      }

      > * {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
    }
  }

  > .actions {
    display: flex;

    margin-right: 10px; // desktop
    @media (max-width: 768px) {
      margin-right: 0;
    }

    > button {
      height: 100%;
    }
  }
}
</style>
