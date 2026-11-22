<template>
  <NcButton
    v-if="cast.state.available || cast.state.connected"
    class="memories-menu-item cast-menu"
    variant="tertiary-no-background"
    :title="title"
    :aria-label="title"
    @click="cast.toggle"
  >
    <template #icon>
      <CastConnectedIcon v-if="cast.state.connected" :size="20" />
      <CastIcon v-else :size="20" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import CastIcon from 'vue-material-design-icons/Cast.vue';
import CastConnectedIcon from 'vue-material-design-icons/CastConnected.vue';

import { t } from '@services/l10n';
import * as cast from '@services/cast';

const title = computed(() =>
  cast.state.connected
    ? t('memories', 'Stop casting to {device}', { device: cast.state.device })
    : t('memories', 'Cast to TV'),
);
</script>
