<template>
  <div v-if="systemConfig" class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      <NcNoteCard :type="isHttps ? 'success' : 'error'">
        {{
          isHttps
            ? t('memories', 'HTTPS is enabled')
            : t(
                'memories',
                'You are accessing this page over an insecure context. Several browser APIs are not available, which will make Memories very slow. Enable HTTPS on your server to improve performance.',
              )
        }}
      </NcNoteCard>
      <NcNoteCard :type="httpVerOk ? 'success' : 'warning'">
        {{
          httpVerOk
            ? t('memories', 'HTTP/2 or HTTP/3 is enabled')
            : t('memories', 'HTTP/2 or HTTP/3 is strongly recommended ({httpVer} detected)', { httpVer })
        }}
      </NcNoteCard>
    </p>

    <p>
      <NcNoteCard :type="systemConfig['memories.db.triggers.fcu'] ? 'success' : 'error'">
        {{
          systemConfig['memories.db.triggers.fcu']
            ? t('memories', 'Database triggers are set up correctly.')
            : t('memories', 'Database triggers not set up; {m} mode in use.', { m: 'trigger compatibility' })
        }}
        <br />
        <template v-if="!systemConfig['memories.db.triggers.fcu']">
          {{ t('memories', 'See the documentation for information on how to resolve this.') }}
          <a target="_blank" href="https://memories.gallery/troubleshooting/#trigger-compatibility-mode">{{
            t('memories', 'External Link')
          }}</a>
        </template>
      </NcNoteCard>
    </p>

    <p v-if="status && status.db_is_sqlite">
      <NcNoteCard type="warning">
        {{ t('memories', 'You are using SQLite, which is not recommended for performance.') }}
      </NcNoteCard>
    </p>

    <p v-if="status && typeof status.innodb_buffer_pool_size === 'number'">
      <NcNoteCard :type="status.innodb_buffer_pool_size >= recommendedBufferPoolSize ? 'success' : 'warning'">
        {{
          t('memories', 'innodb_buffer_pool_size is set to {size} GiB (minimum recommended: {minimum} GiB).', {
            size: gibibytes(status.innodb_buffer_pool_size),
            minimum: gibibytes(recommendedBufferPoolSize),
          })
        }}
      </NcNoteCard>
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';

import { t } from '@services/l10n';

import { useAdminContext } from '../admin-context';

defineOptions({
  name: 'Performance',
  title: t('memories', 'Performance'),
});

const { status, systemConfig } = useAdminContext();

const isHttps = computed((): boolean => {
  return window.location.protocol === 'https:';
});

const httpVer = computed((): string => {
  const entry = window.performance?.getEntriesByType?.('navigation')?.[0] as PerformanceNavigationTiming;
  return entry?.nextHopProtocol || t('memories', 'Unknown');
});

const httpVerOk = computed((): boolean => {
  return httpVer.value === 'h2' || httpVer.value === 'h3';
});

const recommendedBufferPoolSize = computed((): number => {
  return 2 * 1024 ** 3; // 2 GiB
});

function gibibytes(bytes: number): string {
  return (bytes / 1024 ** 3).toFixed(1);
}
</script>
