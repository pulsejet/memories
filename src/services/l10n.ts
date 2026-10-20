import {
  translate as ncTranslate,
  translatePlural as ncTranslatePlural,
  getCanonicalLocale as ncGetCanonicalLocale,
} from '@nextcloud/l10n';

// apps for translation
type TranslateApps = 'memories' | 'recognize';

// utility type to drop the first element of a tuple
type DropFirst<T extends unknown[]> = T extends [any, ...infer U] ? U : never;

// Prevent typos in translations
type TranslateType = (app: TranslateApps, ...args: DropFirst<Parameters<typeof ncTranslate>>) => string;
type TranslatePluralType = (app: TranslateApps, ...args: DropFirst<Parameters<typeof ncTranslatePlural>>) => string;

export const translate = ncTranslate as TranslateType;
export const translatePlural = ncTranslatePlural as TranslatePluralType;
export const t = translate;
export const n = translatePlural;

let _canonicalLocale: string | null = null;
export function getCanonicalLocale(): string {
  return (_canonicalLocale ||= ncGetCanonicalLocale());
}
