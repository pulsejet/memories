import { watch, type DeepReadonly } from 'vue';

import { isRTL, register as registerTranslations, setLanguage, setLocale } from '@nextcloud/l10n';

import { config } from '@services/user-config';
import { nativex } from './api';

import type { IConfig } from '@typings';

/** Apply the cached config, which is assumed to be correct. */
export function applyShellConfig(cfg: DeepReadonly<IConfig>) {
  // Mirror of core/layout.user.php html attributes for the given language
  if (cfg.language) {
    setLanguage(cfg.language.replaceAll('_', '-'));
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
  applyShellConfig(config);
  registerPackedL10N(config.language);

  // Any change in language needs a full reload.
  watch([() => config.language, () => config.locale], () => {
    window.location.reload();
  });
}
