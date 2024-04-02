<template>
  <NcButton
    v-if="isMobile"
    class="memories-menu-item search-menu"
    :title="t('memories', 'Search')"
    :aria-label="t('memories', 'Search')"
    @click="search"
  >
    <template #icon> <MagnifyIcon :size="20" /> </template>
  </NcButton>

  <Searchbar v-else />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import Searchbar from '@components/header/Searchbar.vue';

import * as utils from '@services/utils';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

export default defineComponent({
  name: 'SearchbarMenuItem',
  components: {
    NcButton,
    Searchbar,
    MagnifyIcon,
  },

  data: () => ({
    isMobile: utils.isMobile(),
  }),

  mounted() {
    utils.bus.on('memories:window:resize', () => (this.isMobile = utils.isMobile()));
  },

  methods: {
    search() {
      _m.modals.search();
    },
  },
});
</script>
