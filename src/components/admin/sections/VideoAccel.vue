<template>
  <div class="admin-section">
    <h3>{{ $options.title }}</h3>

    <p>
      {{ t('memories', 'You must first make sure the correct drivers are installed before configuring acceleration.') }}
      <br />
      {{ t('memories', 'Make sure you test hardware acceleration with various options after enabling.') }}
      <br />
      {{ t('memories', 'Do not enable multiple types of hardware acceleration simultaneously.') }}

      <br />
      <br />

      {{
        t(
          'memories',
          'Intel processors supporting QuickSync Video (QSV) as well as some AMD GPUs can be used for transcoding using VA-API acceleration.',
        )
      }}
      <br />
      {{ t('memories', 'For more details on driver installation, check the documentation:') }}
      <a target="_blank" href="https://memories.gallery/hw-transcoding/#va-api">
        {{ t('memories', 'External Link') }}
      </a>

      <NcNoteCard
        :type="vaapiStatusType"
        v-if="status && enableTranscoding && !config['memories.vod.external'] && config['memories.vod.vaapi']"
      >
        {{ vaapiStatusText }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.vaapi']"
        @update:checked="update('memories.vod.vaapi')"
        type="switch"
      >
        {{ t('memories', 'Enable acceleration with VA-API') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.vaapi']"
        :checked.sync="config['memories.vod.vaapi.low_power']"
        @update:checked="update('memories.vod.vaapi.low_power')"
        type="switch"
      >
        {{ t('memories', 'Enable low-power mode (QSV only)') }}
      </NcCheckboxRadioSwitch>

      <br />

      {{ t('memories', 'NVIDIA GPUs can be used for transcoding using the NVENC encoder with the proper drivers.') }}
      <br />
      {{
        t(
          'memories',
          'Depending on the versions of the installed SDK and ffmpeg, you need to specify the scaler to use',
        )
      }}

      <NcNoteCard
        type="warning"
        v-if="status && enableTranscoding && !config['memories.vod.external'] && config['memories.vod.nvenc']"
      >
        {{ t('memories', 'No automated tests are available for NVIDIA acceleration.') }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.nvenc']"
        @update:checked="update('memories.vod.nvenc')"
        type="switch"
      >
        {{ t('memories', 'Enable acceleration with NVENC') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.temporal_aq']"
        @update:checked="update('memories.vod.nvenc.temporal_aq')"
        type="switch"
      >
        {{ t('memories', 'Enable NVENC Temporal AQ') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.scale']"
        value="cuda"
        name="nvence_scaler_radio"
        type="radio"
        class="m-radio"
        @update:checked="update('memories.vod.nvenc.scale')"
        >{{ t('memories', 'CUDA scaler') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.scale']"
        value="npp"
        name="nvence_scaler_radio"
        type="radio"
        @update:checked="update('memories.vod.nvenc.scale')"
        class="m-radio"
        >{{ t('memories', 'NPP scaler') }}
      </NcCheckboxRadioSwitch>

      <br />
      {{
        t(
          'memories',
          'Due to a bug in certain hardware drivers, videos may appear in incorrect orientations when streaming. This can be resolved in some cases by rotating the video on the accelerator.',
        )
      }}
      {{
        t(
          'memories',
          'Some drivers (e.g. AMD and older Intel) do not support hardware accelerated rotation. You can attempt to force software-based transpose in this case.',
        )
      }}
      <br />
      <b>
        {{ t('memories', 'Try this option only if you have incorrectly oriented videos during playback.') }}
      </b>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.use_transpose']"
        @update:checked="update('memories.vod.use_transpose')"
        type="switch"
      >
        {{ t('memories', 'Enable streaming transpose workaround') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.use_transpose']"
        :checked.sync="config['memories.vod.use_transpose.force_sw']"
        @update:checked="update('memories.vod.use_transpose.force_sw')"
        type="switch"
      >
        {{ t('memories', 'Force transpose in software') }}
      </NcCheckboxRadioSwitch>

      {{ t('memories', 'Some NVENC devices have issues with force_key_frames.') }}
      <br />
      <b>{{ t('memories', 'Try this option only if you use NVENC and have issues with video playback.') }}</b>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.use_gop_size']"
        @update:checked="update('memories.vod.use_gop_size')"
        type="switch"
      >
        {{ t('memories', 'Enable streaming GOP size workaround') }}
      </NcCheckboxRadioSwitch>
    </p>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'VideoAccel',
  title: t('memories', 'HW Acceleration'),
  mixins: [AdminMixin],

  computed: {
    vaapiStatusText(): string {
      if (!this.status) return '';

      const dev = '/dev/dri/renderD128';
      if (this.status.vaapi_dev === 'ok') {
        return this.t('memories', 'VA-API device ({dev}) is readable', { dev });
      } else if (this.status.vaapi_dev === 'not_found') {
        return this.t('memories', 'VA-API device ({dev}) not found', { dev });
      } else if (this.status.vaapi_dev === 'not_readable') {
        return this.t('memories', 'VA-API device ({dev}) has incorrect permissions', { dev });
      } else {
        return this.t('memories', 'VA-API device status: {status}', {
          status: this.status.vaapi_dev,
        });
      }
    },

    vaapiStatusType(): string {
      return this.status?.vaapi_dev === 'ok' ? 'success' : 'error';
    },
  },
});
</script>
