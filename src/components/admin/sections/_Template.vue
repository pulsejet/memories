<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>
  </div>
</template>

<script setup lang="ts">
import { t } from '@services/l10n';

import { adminSectionProps, type AdminSectionEmits } from '../useAdminSection';

defineOptions({
  name: 'Template',
  title: t('memories', 'Template'),
});

defineProps(adminSectionProps);
defineEmits<AdminSectionEmits>();
</script>
