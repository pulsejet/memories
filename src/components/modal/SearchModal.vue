<template>
  <div v-if="shown" class="search-overlay" :class="{ visible }" @click.stop="close">
    <Searchbar :auto-focus="true" @select="close" />
  </div>
</template>

<script lang="ts">
import Vue, { defineComponent } from 'vue';

import Searchbar from '@components/header/Searchbar.vue';
import SearchbarMenuItem from '@components/header/SearchbarMenuItem.vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'SearchModal',
  components: {
    Searchbar,
  },

  data: () => ({
    shown: false,
    visible: false,
  }),

  created() {
    // register modal
    console.assert(!_m.modals.search, 'SearchModal created twice');
    _m.modals.search = this.open;

    // create right header button
    const header = document.querySelector<HTMLDivElement>('.header-right');
    if (header && utils.uid) {
      const div = document.createElement('div');
      header.prepend(div);
      const component = new Vue({
        render: (h) => h(SearchbarMenuItem),
        router: this.$router,
      });
      component.$mount(div);

      // remove unified search button
      document.querySelector<HTMLDivElement>('.unified-search-menu')?.remove(); // 29+
      document.querySelector<HTMLDivElement>('.unified-search__button')?.remove(); // 28
      document.querySelector<HTMLDivElement>('.header-menu.unified-search')?.remove(); // 27
    }
  },

  methods: {
    open() {
      this.shown = true;
      setTimeout(() => (this.visible = true), 5);
    },

    close($event?: any) {
      if (!$event || ($event.target as HTMLElement).matches('.search-overlay')) {
        this.visible = false;
        setTimeout(() => (this.shown = false), 160);
      }
    },
  },
});
</script>

<style scoped lang="scss">
.search-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.7);
  z-index: 1000;
  backdrop-filter: blur(2px);
  padding-top: 20px;
  transition: opacity 0.15s ease-out;

  opacity: 0;
  &.visible {
    opacity: 1;
  }
}
</style>
