<template>
  <div class="admin-section">
    <h2>{{ t('memories', 'EXIF Extraction') }}</h2>

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.exiftool)">
        {{ binaryStatus('exiftool', status.exiftool) }}
      </NcNoteCard>
    </template>

    <NcTextField
      :label="t('memories', 'Path to packaged exiftool binary')"
      :label-visible="true"
      :value="config['memories.exiftool']"
      @change="update('memories.exiftool', $event.target.value)"
      disabled
    />

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.perl, false)">
        {{ binaryStatus('perl', status.perl) }}
        {{ t('memories', 'You need perl only if the packaged exiftool binary does not work for some reason.') }}
      </NcNoteCard>
    </template>

    <NcCheckboxRadioSwitch
      :checked.sync="config['memories.exiftool_no_local']"
      @update:checked="update('memories.exiftool_no_local')"
      type="switch"
    >
      {{ t('memories', 'Use system perl (only if exiftool binary does not work)') }}
    </NcCheckboxRadioSwitch>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Exif',
  mixins: [AdminMixin],
});
</script>
