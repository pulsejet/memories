<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <NcNoteCard :type="userConfig.albums_enabled ? 'success' : 'warning'">
      {{
        userConfig.albums_enabled
          ? t('memories', 'Albums support is enabled through the Photos app.')
          : t('memories', 'Albums are disabled because the Photos app is not available.')
      }}
    </NcNoteCard>

    <NcNoteCard :type="userConfig.recognize_enabled ? 'success' : 'warning'">
      {{
        userConfig.recognize_enabled
          ? t('memories', 'Recognize is installed and enabled for face recognition.')
          : userConfig.recognize_installed
            ? t('memories', 'Recognize is installed but not enabled for face recognition.')
            : t('memories', 'Recognize is not installed. Face recognition and object tagging may be unavailable.')
      }}
    </NcNoteCard>
    <NcNoteCard v-if="userConfig.facerecognition_installed" type="success">
      {{ t('memories', 'Face Recognition is installed and enabled') }}
    </NcNoteCard>

    <NcNoteCard :type="userConfig.preview_generator_enabled ? 'success' : 'error'">
      {{
        userConfig.preview_generator_enabled
          ? t('memories', 'Preview generator is installed and enabled. Additional configuration may still be required.')
          : t('memories', 'Preview generator is not installed and configured. This may make Memories very slow.')
      }}
    </NcNoteCard>
  </div>
</template>

<script setup lang="ts">
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';

import { config as userConfig } from '@services/user-config';
import { t } from '@services/l10n';

defineOptions({
  name: 'Apps',
  title: t('memories', 'Recommended Apps'),
});
</script>
