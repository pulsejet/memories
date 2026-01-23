<template>
  <div v-if="isMobile" class="mobile-actions">
    <FilterDropdownButton />
    <NcButton
      class="memories-menu-item search-menu"
      :title="t('memories', 'Search')"
      :aria-label="t('memories', 'Search')"
      @click="search"
    >
      <template #icon> <MagnifyIcon :size="20" /> </template>
    </NcButton>
  </div>

  <div v-else class="desktop-actions">
    <FilterDropdownButton />
    <Searchbar />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import Searchbar from '@components/header/Searchbar.vue';
import FilterDropdownButton from '@components/FilterDropdownButton.vue';

import * as utils from '@services/utils';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

export default defineComponent({
  name: 'SearchbarMenuItem',
  components: {
    NcButton,
    Searchbar,
    FilterDropdownButton,
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

<style lang="scss" scoped>
.mobile-actions,
.desktop-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.mobile-actions {
  flex-direction: row;
}

.desktop-actions {
  flex-direction: row;
}
</style>
