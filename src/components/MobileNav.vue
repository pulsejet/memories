<template>
  <MobileBottomBar id="mobile-nav">
    <router-link
      v-for="link in links"
      :key="link.to"
      :to="link.to"
      class="mobile-bottom-bar-item"
      @click.native="beep"
      replace
      exact-path
    >
      <component :is="link.icon" :size="22" />
      <span class="label">{{ link.text }}</span>
    </router-link>
  </MobileBottomBar>
</template>

<script setup lang="ts">
import { computed, markRaw } from 'vue';

import * as nativex from '@native';

import { config } from '@services/user-config';
import { t } from '@services/l10n';
import MobileBottomBar from '@components/MobileBottomBar.vue';

import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';

const links = computed(() => {
  const list = [
    { to: '/', icon: markRaw(ImageMultipleIcon), text: t('memories', 'Photos') },
    { to: '/explore', icon: markRaw(SearchIcon), text: t('memories', 'Explore') },
  ];
  if (config.albums_enabled) {
    list.push({ to: '/albums', icon: markRaw(AlbumIcon), text: t('memories', 'Albums') });
  }
  return list;
});

function beep() {
  nativex.playTouchSound();
}
</script>

<style lang="scss">
:root {
  // iOS PWA shenanigans
  --mobile-nav-height: calc(58px + max(calc(env(safe-area-inset-bottom) - 15px), 0px));
}

// Show correct nav depending on screen size
#mobile-nav {
  contain: strict;
  display: none;
}

@media (max-width: 768px) {
  :root {
    --toast-offset-block-end: var(--mobile-nav-height);
  }

  #content-vue > .app-navigation {
    display: none;
  }

  #mobile-nav {
    display: flex;
  }

  // Reserve space per view so its height is stable when the nav slides away
  #app-content-vue > .router-outlet > .route-view.has-nav {
    height: calc(100% - var(--mobile-nav-height));
  }
}
</style>

<style lang="scss" scoped>
#mobile-nav {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 1; // above the leaving tab, below the sliding view
  background-color: var(--color-main-background);
  color: var(--color-main-text);
  height: var(--mobile-nav-height);
}
</style>
