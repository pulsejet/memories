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

import userConfig from '@services/user-config';
import { t } from '@services/l10n';
import MobileBottomBar from '@components/MobileBottomBar.vue';

import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';

const config = userConfig.use();

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
  #content-vue > .app-navigation {
    display: none;
  }

  #mobile-nav {
    display: flex;
  }

  // Make space for the nav
  #app-content-vue > .router-outlet.has-nav {
    height: calc(100% - var(--mobile-nav-height));
  }
}
</style>

<style lang="scss" scoped>
#mobile-nav {
  background-color: var(--color-main-background);
  color: var(--color-main-text);
  height: var(--mobile-nav-height);
}
</style>
