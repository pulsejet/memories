<template>
  <div id="mobile-nav">
    <router-link to="/">
      <ImageMultipleIcon />
      {{ t('memories', 'Photos') }}
    </router-link>

    <router-link to="/explore">
      <SearchIcon />
      {{ t('memories', 'Explore') }}
    </router-link>

    <router-link to="/albums">
      <AlbumIcon />
      {{ t('memories', 'Albums') }}
    </router-link>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

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
});
</script>

<style lang="scss">
:root {
  --mobile-nav-width: 64px;
}

// Show correct nav depending on screen size
#mobile-nav {
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
  #app-content-vue > .router-outlet {
    height: calc(100% - var(--mobile-nav-width));
  }
}
</style>

<style lang="scss" scoped>
#mobile-nav {
  background-color: var(--color-main-background);
  height: var(--mobile-nav-width);
  text-align: center;
  padding: 8px;
  padding-top: 6px;

  :deep a {
    flex: 1 1 0px;
    opacity: 0.75;

    span.material-design-icon {
      border-radius: 20px;
      padding: 4px;
      max-width: 80px;
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