<template>
  <NcButton
    v-if="windowWidthIsMobile"
    class="memories-menu-item search-menu"
    variant="tertiary-no-background"
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

import NcButton from '@nextcloud/vue/components/NcButton';

import Searchbar from '@components/header/Searchbar.vue';

import CommonMixin from '@mixins/CommonMixin';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

export default defineComponent({
  name: 'SearchbarMenuItem',
  components: {
    NcButton,
    Searchbar,
    MagnifyIcon,
  },

  mixins: [CommonMixin],

  methods: {
    search() {
      _m.modals.search();
    },
  },
});
</script>
