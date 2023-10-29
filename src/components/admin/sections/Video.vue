<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{ t('memories', 'Live transcoding provides for adaptive streaming of videos using HLS.') }}
      <br />
      {{
        t(
          'memories',
          'Note that this may be very CPU intensive without hardware acceleration, and transcoding will not be used for external storage.',
        )
      }}

      <NcCheckboxRadioSwitch
        :checked.sync="enableTranscoding"
        @update:checked="update('memories.vod.disable', !enableTranscoding)"
        type="switch"
      >
        {{ t('memories', 'Enable Transcoding') }}
      </NcCheckboxRadioSwitch>

      <template v-if="status">
        <NcNoteCard :type="binaryStatusType(status.ffmpeg)">
          {{ binaryStatus('ffmpeg', status.ffmpeg) }}
        </NcNoteCard>
        <NcNoteCard :type="binaryStatusType(status.ffprobe)">
          {{ binaryStatus('ffprobe', status.ffprobe) }}
        </NcNoteCard>
      </template>

      <NcTextField
        :label="t('memories', 'ffmpeg path')"
        :label-visible="true"
        :value="config['memories.vod.ffmpeg']"
        @change="update('memories.vod.ffmpeg', $event.target.value)"
        :disabled="!enableTranscoding"
      />

      <NcTextField
        :label="t('memories', 'ffprobe path')"
        :label-visible="true"
        :value="config['memories.vod.ffprobe']"
        @change="update('memories.vod.ffprobe', $event.target.value)"
        :disabled="!enableTranscoding"
      />

      <br />
      {{ t('memories', 'Global default video quality (user may override)') }}
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.video_default_quality']"
        value="0"
        name="vdq_radio"
        type="radio"
        @update:checked="update('memories.video_default_quality')"
        >{{ t('memories', 'Auto (adaptive transcode)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.video_default_quality']"
        value="-1"
        name="vdq_radio"
        type="radio"
        @update:checked="update('memories.video_default_quality')"
        >{{ t('memories', 'Original (transcode with max quality)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.video_default_quality']"
        value="-2"
        name="vdq_radio"
        type="radio"
        @update:checked="update('memories.video_default_quality')"
        >{{ t('memories', 'Direct (original video file without transcode)') }}
      </NcCheckboxRadioSwitch>
    </p>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Video',
  title: t('memories', 'Video Streaming'),
  mixins: [AdminMixin],
});
</script>
