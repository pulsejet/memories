<template>
  <div v-if="systemConfig" class="admin-section">
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
        v-if="
          status && enableTranscoding && !systemConfig['memories.vod.external'] && systemConfig['memories.vod.vaapi']
        "
      >
        {{ vaapiStatusText }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        v-model="systemConfig['memories.vod.vaapi']"
        @update:model-value="update('memories.vod.vaapi')"
        type="switch"
      >
        {{ t('memories', 'Enable acceleration with VA-API') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !systemConfig['memories.vod.vaapi']"
        v-model="systemConfig['memories.vod.vaapi.low_power']"
        @update:model-value="update('memories.vod.vaapi.low_power')"
        type="switch"
      >
        {{ t('memories', 'Enable low-power mode (QSV only)') }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :disabled="!enableTranscoding || !systemConfig['memories.vod.vaapi']"
        :label="t('memories', 'VA-API device path')"
        :label-visible="true"
        :model-value="systemConfig['memories.vod.vaapi.device']"
        @change="update('memories.vod.vaapi.device', $event.target.value)"
      />

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
        v-if="
          status && enableTranscoding && !systemConfig['memories.vod.external'] && systemConfig['memories.vod.nvenc']
        "
      >
        {{ t('memories', 'No automated tests are available for NVIDIA acceleration.') }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        v-model="systemConfig['memories.vod.nvenc']"
        @update:model-value="update('memories.vod.nvenc')"
        type="switch"
      >
        {{ t('memories', 'Enable acceleration with NVENC') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !systemConfig['memories.vod.nvenc']"
        v-model="systemConfig['memories.vod.nvenc.temporal_aq']"
        @update:model-value="update('memories.vod.nvenc.temporal_aq')"
        type="switch"
      >
        {{ t('memories', 'Enable NVENC Temporal AQ') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !systemConfig['memories.vod.nvenc']"
        v-model="systemConfig['memories.vod.nvenc.scale']"
        value="cuda"
        name="nvence_scaler_radio"
        type="radio"
        class="m-radio"
        @update:model-value="update('memories.vod.nvenc.scale')"
        >{{ t('memories', 'CUDA scaler') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !systemConfig['memories.vod.nvenc']"
        v-model="systemConfig['memories.vod.nvenc.scale']"
        value="npp"
        name="nvence_scaler_radio"
        type="radio"
        @update:model-value="update('memories.vod.nvenc.scale')"
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
        v-model="systemConfig['memories.vod.use_transpose']"
        @update:model-value="update('memories.vod.use_transpose')"
        type="switch"
      >
        {{ t('memories', 'Enable streaming transpose workaround') }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !systemConfig['memories.vod.use_transpose']"
        v-model="systemConfig['memories.vod.use_transpose.force_sw']"
        @update:model-value="update('memories.vod.use_transpose.force_sw')"
        type="switch"
      >
        {{ t('memories', 'Force transpose in software') }}
      </NcCheckboxRadioSwitch>

      {{ t('memories', 'Some NVENC devices have issues with force_key_frames.') }}
      <br />
      <b>{{ t('memories', 'Try this option only if you use NVENC and have issues with video playback.') }}</b>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        v-model="systemConfig['memories.vod.use_gop_size']"
        @update:model-value="update('memories.vod.use_gop_size')"
        type="switch"
      >
        {{ t('memories', 'Enable streaming GOP size workaround') }}
      </NcCheckboxRadioSwitch>
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';

import { t } from '@services/l10n';

import { useAdminSection } from '../useAdminSection';

defineOptions({
  name: 'VideoAccel',
  title: t('memories', 'HW Acceleration'),
});

const { status, systemConfig, update, enableTranscoding } = useAdminSection();

const vaapiStatusText = computed((): string => {
  if (!status.value) return '';

  const dev = systemConfig.value?.['memories.vod.vaapi.device'] || '/dev/dri/renderD128';
  if (status.value.vaapi_dev === 'ok') {
    return t('memories', 'VA-API device ({dev}) is readable', { dev });
  } else if (status.value.vaapi_dev === 'not_found') {
    return t('memories', 'VA-API device ({dev}) not found', { dev });
  } else if (status.value.vaapi_dev === 'not_readable') {
    return t('memories', 'VA-API device ({dev}) has incorrect permissions', { dev });
  } else {
    return t('memories', 'VA-API device status: {status}', {
      status: status.value.vaapi_dev,
    });
  }
});

const vaapiStatusType = computed((): 'success' | 'error' => {
  return status.value?.vaapi_dev === 'ok' ? 'success' : 'error';
});
</script>
