<template>
  <NcButton
    v-if="windowDims.isMobile"
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

<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton';

import Searchbar from '@components/header/Searchbar.vue';

import { windowDims } from '@services/common';
import { t } from '@services/l10n';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

function search() {
  _m.modals.search();
}
</script>
