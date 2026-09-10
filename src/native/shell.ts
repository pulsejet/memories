import { isRTL, register as registerTranslations, setLanguage, setLocale } from '@nextcloud/l10n';

import staticConfig from '@services/static-config';
import { bus } from '@services/utils/event-bus';
import { nativex } from './api';

import type { IConfig } from '@typings';

/** Apply the cached config, which is assumed to be correct. */
export function applyShellConfig(cfg: IConfig) {
  // Mirror of core/layout.user.php html attributes for the given language
  if (cfg.language) {
    setLanguage(cfg.language.replace(/_/g, '-'));
  }
  if (cfg.locale) {
    setLocale(cfg.locale);
  }
  document.body.dir = isRTL() ? 'rtl' : 'ltr';
}

/** Register the l10n bundle packed into the shell. */
function registerPackedL10N(language: string): void {
  const bundle = globalThis.__packed_l10n?.[language];
  if (!bundle || typeof bundle !== 'object') return;
  const rec = bundle as Record<string, unknown>;
  const inner = rec.translations ?? rec;
  if (inner && typeof inner === 'object') {
    registerTranslations('memories', inner as Record<string, string>);
  }
}

/**
 * Replicate various aspect of the the server template
 * in the native shell such as language.
 */
export function initShellSync(): void {
  if (!nativex) return;
  const cfg = staticConfig.getDefault();
  applyShellConfig(cfg);
  registerPackedL10N(cfg.language);

  // Any change in language needs a full reload.
  let appliedSnap = JSON.stringify([cfg.language, cfg.locale]);
  bus.on('memories:user-config-changed', () => {
    const fresh = staticConfig.getDefault();
    const snap = JSON.stringify([fresh.language, fresh.locale]);
    if (snap === appliedSnap) return;
    window.location.reload();
  });
}
