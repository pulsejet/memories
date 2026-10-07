<template>
  <div v-if="systemConfig" class="admin-section">
    <h3>{{ $options.title }}</h3>
    <p>
      {{
        t(
          'memories',
          'Memories uses the go-vod transcoder. You can run go-vod exernally (e.g. in a separate Docker container for hardware acceleration) or use the built-in transcoder. To use an external transcoder, enable the following option and follow the instructions in the documentation:',
        )
      }}
      <a target="_blank" href="https://memories.gallery/hw-transcoding/">
        {{ t('memories', 'External Link') }}
      </a>

      <template v-if="status && enableTranscoding">
        <NcNoteCard :type="binaryStatusType(status.govod)">
          {{ binaryStatus('go-vod', status.govod) }}
        </NcNoteCard>
        <NcNoteCard v-for="server in status.govod_servers" :key="server.server" :type="serviceStatusType(server)">
          go-vod {{ serviceStatus(server) }}
        </NcNoteCard>
      </template>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        v-model="systemConfig['memories.vod.external']"
        @update:model-value="update('memories.vod.external')"
        type="switch"
      >
        {{ t('memories', 'Enable external transcoder') }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :disabled="!enableTranscoding || systemConfig['memories.vod.external']"
        :label="t('memories', 'Binary path (local only)')"
        :label-visible="true"
        :model-value="systemConfig['memories.vod.path']"
        @change="update('memories.vod.path', $event.target.value)"
      />

      <NcTextField
        :disabled="!enableTranscoding || systemConfig['memories.vod.external']"
        :label="t('memories', 'Bind address (local only)')"
        :label-visible="true"
        :model-value="systemConfig['memories.vod.bind']"
        @change="update('memories.vod.bind', $event.target.value)"
      />

      <NcTextField
        :disabled="!enableTranscoding || systemConfig['memories.vod.external']"
        :label="t('memories', 'Nextcloud URL for transcoder (local only)')"
        :label-visible="true"
        :model-value="systemConfig['memories.vod.nc_url']"
        @change="update('memories.vod.nc_url', $event.target.value)"
      />

      <NcTextField
        :disabled="!enableTranscoding || !systemConfig['memories.vod.external']"
        :label="t('memories', 'Connection addresses (comma separated)')"
        :label-visible="true"
        :model-value="systemConfig['memories.vod.connect'].join(', ')"
        @change="updateConnect($event.target.value)"
      />

      <NcTextField
        type="number"
        min="15"
        max="45"
        placeholder="25"
        :disabled="!enableTranscoding"
        :label="t('memories', 'Quality Factor (15 - 45) (default 25)')"
        :label-visible="true"
        :model-value="String(systemConfig['memories.vod.qf'])"
        @change="update('memories.vod.qf', Number($event.target.value))"
      />
    </p>
  </div>
</template>

<script setup lang="ts">
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';

import { t } from '@services/l10n';

import { useAdminContext } from '../admin-context';
import { binaryStatus, binaryStatusType, serviceStatus, serviceStatusType } from '../admin-utils';

defineOptions({
  name: 'VideoTranscoder',
  title: t('memories', 'Transcoder'),
});

const { status, systemConfig, update, enableTranscoding } = useAdminContext();

function updateConnect(value: string) {
  const array = value.split(',').map((s) => s.trim());
  update('memories.vod.connect', array.filter(Boolean));
}
</script>
