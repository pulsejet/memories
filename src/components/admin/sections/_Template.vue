<template>
  <div class="admin-section"></div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@nextcloud/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Template',
  title: t('memories', 'Template'),
  mixins: [AdminMixin],
});
</script>
