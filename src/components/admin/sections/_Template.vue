<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from 'services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Template',
  title: t('memories', 'Template'),
  mixins: [AdminMixin],
});
</script>
