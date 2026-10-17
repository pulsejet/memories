<template>
  <div class="top-matter">
    <span class="name">{{ t('memories', 'Trash') }}</span>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton
          :aria-label="t('memories', 'Empty trash')"
          :title="t('memories', 'Empty trash')"
          :disabled="!!loading"
          @click="emptyTrash"
          close-after-click
        >
          {{ t('memories', 'Empty trash') }}
          <template #icon> <DeleteEmptyIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { t } from '@services/l10n';
import { confirmDestructive } from '@services/utils/dialog';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import DeleteEmptyIcon from 'vue-material-design-icons/DeleteEmpty.vue';

defineOptions({
  name: 'TrashTopMatter',
});

const loading = ref(0);

async function emptyTrash() {
  if (loading.value) return;
  if (
    !(await confirmDestructive({
      title: t('memories', 'Empty trash?'),
      message: t('memories', 'All files in trash will be permanently deleted. This action cannot be undone.'),
      confirm: t('memories', 'Empty trash'),
      cancel: t('memories', 'Cancel'),
    }))
  ) {
    return;
  }

  loading.value++;
  try {
    if (await dav.emptyTrashbin()) {
      utils.bus.emit('memories:timeline:hard-refresh', null);
    }
  } finally {
    loading.value--;
  }
}
</script>
