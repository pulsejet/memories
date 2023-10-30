<template>
  <div id="mobile-nav">
    <router-link to="/" @click.native="linkClick" replace exact-path>
      <ImageMultipleIcon :size="22" />
      {{ t('memories', 'Photos') }}
    </router-link>

    <router-link to="/explore" @click.native="linkClick" replace exact-path>
      <SearchIcon :size="22" />
      {{ t('memories', 'Explore') }}
    </router-link>

    <router-link to="/albums" @click.native="linkClick" replace exact-path>
      <AlbumIcon :size="22" />
      {{ t('memories', 'Albums') }}
    </router-link>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as nativex from '@native';

import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import SearchIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';

export default defineComponent({
  name: 'MobileNav',

  components: {
    ImageMultipleIcon,
    SearchIcon,
    AlbumIcon,
  },

  methods: {
    linkClick() {
      nativex.playTouchSound();
    },
  },
});
</script>

<style lang="scss">
:root {
  --mobile-nav-height: 58px;
}

// Show correct nav depending on screen size
#mobile-nav {
  contain: strict;
  display: none;
}

@media (max-width: 768px) {
  #app-navigation-vue {
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
  height: var(--mobile-nav-height);
  text-align: center;
  padding: 8px;
  padding-top: 3px;
  font-size: 0.9em;
  overflow: hidden;

  :deep a {
    flex: 1 1 0px;
    opacity: 0.75;

    span.material-design-icon {
      border-radius: 20px;
      padding: 4px;
      max-width: 70px;
      margin: 0 auto;
    }

    &.router-link-exact-active {
      opacity: 1;

      span.material-design-icon {
        background: var(--color-primary-element-light);
      }
    }
  }
}
</style>
