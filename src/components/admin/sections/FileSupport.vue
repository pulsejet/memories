<template>
  <div class="admin-section">
    <h2>{{ t('memories', 'File Support') }}</h2>

    {{ t('memories', 'You can configure the enabled Nextcloud preview providers below.') }}
    {{ t('memories', 'If you are using Imaginary for preview generation, you can ignore this section.') }}
    {{ t('memories', 'To enable RAW support, install the Camera RAW Previews app.') }}
    <a href="https://memories.gallery/file-types/" target="_blank">
      {{ t('memories', 'Documentation.') }}
    </a>
    <br />

    <NcCheckboxRadioSwitch
      type="switch"
      v-for="(provider, klass) in knownPreviewProviders"
      :key="klass"
      :checked="hasProvider(klass)"
      @update:checked="updateProvider(klass, $event)"
      >{{ provider.name }}
    </NcCheckboxRadioSwitch>

    {{ t('memories', 'The following MIME types are configured for preview generation.') }}

    <br />
    <code v-if="status"
      ><template v-for="mime in status.mimes">{{ mime }}<br :key="mime" /></template
    ></code>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@nextcloud/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'FileSupport',
  mixins: [AdminMixin],

  data: () => ({
    knownPreviewProviders: {
      'OC\\Preview\\Image': {
        name: t('memories', 'Images (JPEG, PNG, GIF, BMP)'),
      },
      'OC\\Preview\\HEIC': {
        name: t('memories', 'HEIC (Imagick)'),
      },
      'OC\\Preview\\TIFF': {
        name: t('memories', 'TIFF (Imagick)'),
      },
      'OC\\Preview\\Movie': {
        name: t('memories', 'Videos (ffmpeg)'),
      },
    },
  }),

  methods: {
    providers() {
      return this.config['enabledPreviewProviders'];
    },

    hasProvider(klass: string): boolean {
      return this.providers().includes(klass);
    },

    updateProvider(klass: string, enabled: boolean) {
      if (enabled === this.hasProvider(klass)) return;

      if (enabled) {
        this.providers().push(klass);
      } else {
        this.config['enabledPreviewProviders'] = this.providers().filter((k) => k !== klass);
      }

      this.update('enabledPreviewProviders');
    },
  },
});
</script>
