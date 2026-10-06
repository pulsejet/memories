import { computed, type PropType } from 'vue';
import axios from '@nextcloud/axios';

import { t } from '@services/l10n';

import type { IBinaryStatus, IServiceStatus, ISystemConfig, ISystemStatus } from './AdminTypes';
import type { IConfig } from '@typings';

/** Props shared by all admin sections. */
export const adminSectionProps = {
  status: {
    type: Object as PropType<ISystemStatus | null>,
    default: null,
    required: false,
  },
  config: {
    type: Object as PropType<ISystemConfig>,
    required: true,
  },
  sconfig: {
    type: Object as PropType<IConfig>,
    required: true,
  },
} as const;

/** Emits shared by all admin sections. */
export type AdminSectionEmits = {
  (e: 'update', key: keyof ISystemConfig, value: any): void;
};

/** Composition replacement for AdminMixin. */
export function useAdminSection(
  props: { status: ISystemStatus | null; config: ISystemConfig },
  emit: AdminSectionEmits,
) {
  function update(key: keyof ISystemConfig, value: any = null) {
    emit('update', key, value);
  }

  function binaryStatus(name: string, status: IBinaryStatus): string {
    const noescape = {
      escape: false,
      sanitize: false,
    };
    if (status === 'ok') {
      return t('memories', '{name} binary exists and is executable.', { name });
    } else if (status === 'not_found') {
      return t('memories', '{name} binary not found.', { name });
    } else if (status === 'not_executable') {
      return t('memories', '{name} binary is not executable.', { name });
    } else if (status.startsWith('test_fail')) {
      return t('memories', '{name} failed test: {info}.', { name, info: status.slice(10) }, 0, noescape);
    } else if (status.startsWith('test_ok')) {
      return t(
        'memories',
        '{name} binary exists and is usable ({info}).',
        { name, info: status.slice(8) },
        0,
        noescape,
      );
    } else {
      return t('memories', '{name} binary status: {status}.', { name, status });
    }
  }

  function binaryStatusType(status: IBinaryStatus, critical = true): 'success' | 'warning' | 'error' {
    if (binaryStatusOk(status)) {
      return 'success';
    } else if (status === 'not_found' || status === 'not_executable' || status.startsWith('test_fail')) {
      return critical ? 'error' : 'warning';
    } else {
      return 'warning';
    }
  }

  function binaryStatusOk(status: IBinaryStatus): boolean {
    return status === 'ok' || status.startsWith('test_ok');
  }

  function serviceStatus(s: IServiceStatus): string {
    if (s.healthy) {
      if (s.latencyMs !== undefined && s.latencyMs !== null) {
        return t('memories', '{srv} - Healthy ({version}, latency={latency}ms).', {
          srv: s.server,
          version: s.detail,
          latency: s.latencyMs,
        });
      }
      return t('memories', '{srv} - Healthy ({version}).', {
        srv: s.server,
        version: s.detail,
      });
    }
    return t('memories', '{srv} - Unhealthy ({info}).', {
      srv: s.server,
      info: s.detail,
    });
  }

  function serviceStatusType(s: IServiceStatus): 'success' | 'error' {
    return s.healthy ? 'success' : 'error';
  }

  const requestToken = computed(() => (<any>axios.defaults.headers).requesttoken);

  const actionToken = computed(() => props.status?.action_token || '');

  /** Reverse of memories.vod.disable, unfortunately */
  const enableTranscoding = computed({
    get: () => !props.config['memories.vod.disable'],
    set: (value: boolean) => {
      props.config['memories.vod.disable'] = !value;
    },
  });

  return {
    update,
    binaryStatus,
    binaryStatusType,
    binaryStatusOk,
    serviceStatus,
    serviceStatusType,
    requestToken,
    actionToken,
    enableTranscoding,
  };
}
