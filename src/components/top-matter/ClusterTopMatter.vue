<template>
  <div class="top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>
    <span class="name">{{ name || viewname }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { t } from '@services/l10n';
import * as strings from '@services/strings';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';

defineOptions({
  name: 'ClusterTopMatter',
});

const route = useRoute();
const router = useRouter();

const viewname = computed((): string => {
  return strings.viewName(route.name?.toString() ?? '');
});

const name = computed((): string | null => {
  switch (route.name) {
    case _m.routes.Tags.name:
      return t('recognize', route.params.name?.toString());
    default:
      return null;
  }
});

function back() {
  router.go(-1);
}
</script>
