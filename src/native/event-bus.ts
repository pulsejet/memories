import { nxAsync } from './api';
import { bus } from '@services/utils/event-bus';

/**
 * Native-to-web event channel over the async reply proxy.
 * Falls back to evaluateJavascript on the native side until registered.
 */
export function initEventBus() {
  const asyncBridge = (globalThis as any).nativex_async;
  if (!asyncBridge?.postMessage) return;

  asyncBridge.onmessage = (e: any) => {
    const raw = typeof e === 'string' ? e : (e?.data ?? e);
    if (typeof raw !== 'string' || !raw) return;
    try {
      const msg = JSON.parse(raw);
      const evt = msg?.['event-bus'] ?? msg;
      if (!evt?.event || typeof evt.event !== 'string') return;
      bus.emit(evt.event, evt.data ?? null);
    } catch {
      // Ignore malformed messages.
    }
  };

  nxAsync('setupEventBus')?.();
}
