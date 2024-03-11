<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      <NcNoteCard :type="isHttps ? 'success' : 'warning'">
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
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Performance',
  title: t('memories', 'Performance'),
  mixins: [AdminMixin],

  computed: {
    isHttps(): boolean {
      return window.location.protocol === 'https:';
    },

    httpVer(): string {
      const entry = window.performance?.getEntriesByType?.('navigation')?.[0] as PerformanceNavigationTiming;
      return entry?.nextHopProtocol || this.t('memories', 'Unknown');
    },

    httpVerOk(): boolean {
      return this.httpVer === 'h2' || this.httpVer === 'h3';
    },
  },
});
</script>
