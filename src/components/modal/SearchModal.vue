<template>
  <div></div>
</template>

<script lang="ts">
import Vue, { defineComponent } from 'vue';

import Searchbar from '@components/header/Searchbar.vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'SearchModal',

  created() {
    // create right header button
    const header = document.querySelector<HTMLDivElement>('.header-right');
    if (header && utils.uid) {
      const div = document.createElement('div');
      header.prepend(div);
      const component = new Vue({
        render: (h) => h(Searchbar),
        router: this.$router,
      });
      component.$mount(div);

      // remove unified search button
      document.querySelector<HTMLDivElement>('.unified-search-menu')?.remove();
    }
  },
});
</script>

<style scoped lang="scss"></style>
