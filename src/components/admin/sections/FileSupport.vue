<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    {{ t('memories', 'You can configure the enabled Nextcloud preview providers below.') }}
    {{ t('memories', 'If you are using Imaginary for preview generation, you can ignore this section.') }}
    {{ t('memories', 'To enable RAW support, install the Camera RAW Previews app.') }}
    <a href="https://memories.gallery/file-types/" target="_blank">
      {{ t('memories', 'Documentation.') }}
    </a>
    <br />

    <template v-if="status">
      <NcNoteCard v-if="status.imagick" type="success">
        {{ t('memories', 'PHP-Imagick is available [{version}].', { version: status.imagick }) }}
      </NcNoteCard>
      <NcNoteCard v-else type="error">
        {{ t('memories', 'PHP-Imagick is not available.') }}
        {{ t('memories', 'Image editing will not work correctly.') }}
        {{ t('memories', 'Thumbnail generation may not work for some formats (HEIC, TIFF).') }}
      </NcNoteCard>

      <NcNoteCard :type="binaryStatusType(status.ffmpeg_preview)">
        {{ binaryStatus('ffmpeg preview', status.ffmpeg_preview) }}
        {{
          binaryStatusOk(status.ffmpeg_preview)
            ? t('memories', 'Thumbnails for videos will be generated with this binary.')
            : t('memories', 'Thumbnail generation may not work for videos.')
        }}
      </NcNoteCard>

      <NcNoteCard v-if="hasProvider('OC\\Preview\\Imaginary')" type="warning">
        {{
          t(
            'memories',
            'Imaginary is enabled for preview generation. This will override other preview providers. We currently recommend against using Imaginary due to multiple bugs in handling of HEIC and GIF files.',
          )
        }}
      </NcNoteCard>
    </template>

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
    <code v-if="status">
      <span v-for="mime in status.mimes" :key="mime">{{ mime }}<br /></span>
    </code>

    <br />

    {{ t('memories', 'Max preview size (trade-off between quality and storage requirements).') }}
    <a href="https://memories.gallery/config/#preview-storage" target="_blank">
      {{ t('memories', 'Documentation.') }}
    </a>
    <br />
    <NcCheckboxRadioSwitch
      class="preview-box"
      v-for="size in previewSizes"
      :key="size"
      :checked="String(config['preview_max_x'])"
      :value="String(size)"
      name="previewsize_radio"
      type="radio"
      @update:checked="updatePreviewSize(size)"
      >{{ size }}px
    </NcCheckboxRadioSwitch>

    <NcTextField
      type="number"
      placeholder="1024"
      :label="t('memories', 'Max memory for preview generation (MB)')"
      :label-visible="true"
      :value="String(config['preview_max_memory'])"
      @change="update('preview_max_memory', Number($event.target.value))"
    />

    <NcTextField
      type="number"
      placeholder="50"
      :label="t('memories', 'Max size of file to generate previews for (MB)')"
      :label-visible="true"
      :value="String(config['preview_max_filesize_image'])"
      @change="update('preview_max_filesize_image', Number($event.target.value))"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'FileSupport',
  title: t('memories', 'File Support'),
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
      'OC\\Preview\\Imaginary': {
        name: t('memories', 'Imaginary (not recommended)'),
      },
    },

    previewSizes: [512, 1024, 2048, 4096, 8192],
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

    async updatePreviewSize(size: number | string) {
      this.update('preview_max_x', Number(size));
      await new Promise((resolve) => setTimeout(resolve, 1000)); // Hack to prevent config race
      this.update('preview_max_y', Number(size));
    },
  },
});
</script>

<style lang="scss" scoped>
.preview-box {
  display: inline-block !important;
  margin: 0 10px;
}
</style>
