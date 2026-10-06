<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.exiftool)">
        {{ binaryStatus('exiftool', status.exiftool) }}
      </NcNoteCard>
    </template>

    <NcTextField
      :label="t('memories', 'Path to packaged exiftool binary')"
      :label-visible="true"
      :model-value="systemConfig['memories.exiftool']"
      @change="update('memories.exiftool', $event.target.value)"
      readonly
    />

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.perl, false)">
        {{ binaryStatus('perl', status.perl) }}
        {{ t('memories', 'You need perl only if the packaged exiftool binary does not work for some reason.') }}
      </NcNoteCard>
    </template>

    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.exiftool_no_local']"
      @update:model-value="update('memories.exiftool_no_local')"
      type="switch"
    >
      {{ t('memories', 'Use system perl (only if exiftool binary does not work)') }}
    </NcCheckboxRadioSwitch>
  </div>
</template>

<script setup lang="ts">
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';

import { t } from '@services/l10n';

import { adminSectionProps, useAdminSection, type AdminSectionEmits } from '../useAdminSection';

defineOptions({
  name: 'Exif',
  title: t('memories', 'EXIF Extraction'),
});

const props = defineProps(adminSectionProps);
const emit = defineEmits<AdminSectionEmits>();

const { update, binaryStatus, binaryStatusType } = useAdminSection(props, emit);
</script>
