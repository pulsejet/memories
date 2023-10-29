<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <NcNoteCard :type="sconfig.albums_enabled ? 'success' : 'warning'">
      {{
        sconfig.albums_enabled
          ? t('memories', 'Albums support is enabled through the Photos app.')
          : t('memories', 'Albums are disabled because the Photos app is not available.')
      }}
    </NcNoteCard>

    <NcNoteCard :type="sconfig.recognize_enabled ? 'success' : 'warning'">
      {{
        sconfig.recognize_enabled
          ? t('memories', 'Recognize is installed and enabled for face recognition.')
          : sconfig.recognize_installed
          ? t('memories', 'Recognize is installed but not enabled for face recognition.')
          : t('memories', 'Recognize is not installed. Face recognition and object tagging may be unavailable.')
      }}
    </NcNoteCard>
    <NcNoteCard v-if="sconfig.facerecognition_installed" type="success">
      {{ t('memories', 'Face Recognition is installed and enabled') }}
    </NcNoteCard>

    <NcNoteCard :type="sconfig.preview_generator_enabled ? 'success' : 'error'">
      {{
        sconfig.preview_generator_enabled
          ? t('memories', 'Preview generator is installed and enabled. Additional configuration may still be required.')
          : t('memories', 'Preview generator is not installed and configured. This may make Memories very slow.')
      }}
    </NcNoteCard>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from 'services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Apps',
  title: t('memories', 'Recommended Apps'),
  mixins: [AdminMixin],
});
</script>
