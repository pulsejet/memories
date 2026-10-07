<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>
  </div>
</template>

<script setup lang="ts">
import { t } from '@services/l10n';

defineOptions({
  name: 'Template',
  title: t('memories', 'Template'),
});
</script>
