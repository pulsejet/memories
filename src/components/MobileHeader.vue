<template>
  <div
    id="mobile-header"
    class="timeline-scroller-gap"
    :class="{
      visible: !isScrollDown,
    }"
  >
    <div class="logo">
      <a :href="homeUrl"><XImg :src="banner" :svg-tag="true" /></a>
    </div>

    <div class="actions">
      <UploadMenuItem />
      <FilterDropdownButton />
      <SearchbarMenuItem />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { generateUrl } from '@nextcloud/router';

import UploadMenuItem from '@components/header/UploadMenuItem.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';
import SearchbarMenuItem from '@components/header/SearchbarMenuItem.vue';

import * as utils from '@services/utils';

import banner from '@assets/banner.svg';

export default defineComponent({
  name: 'MobileHeader',
  components: {
    UploadMenuItem,
    FilterDropdownButton,
    SearchbarMenuItem,
  },

  data: () => ({
    banner,
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
