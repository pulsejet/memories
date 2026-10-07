import { t } from '@services/l10n';

import type { IBinaryStatus, IServiceStatus } from './AdminTypes';

/** Human-readable status text for an external binary. */
export function binaryStatus(name: string, status: IBinaryStatus): string {
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
    return t('memories', '{name} binary exists and is usable ({info}).', { name, info: status.slice(8) }, 0, noescape);
  } else {
    return t('memories', '{name} binary status: {status}.', { name, status });
  }
}

/** NoteCard severity for a binary status; failures are errors only if critical. */
export function binaryStatusType(status: IBinaryStatus, critical = true): 'success' | 'warning' | 'error' {
  if (binaryStatusOk(status)) {
    return 'success';
  } else if (status === 'not_found' || status === 'not_executable' || status.startsWith('test_fail')) {
    return critical ? 'error' : 'warning';
  } else {
    return 'warning';
  }
}

/** Whether a binary status means the binary is usable. */
export function binaryStatusOk(status: IBinaryStatus): boolean {
  return status === 'ok' || status.startsWith('test_ok');
}

/** Human-readable status text for a go-vod server, including latency when known. */
export function serviceStatus(s: IServiceStatus): string {
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

/** NoteCard severity for a go-vod server. */
export function serviceStatusType(s: IServiceStatus): 'success' | 'error' {
  return s.healthy ? 'success' : 'error';
}
