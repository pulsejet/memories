import { translate as t, translatePlural as n } from '@nextcloud/l10n';

// apps for translation
type TranslateApps = 'memories' | 'recognize';

// utility type to drop the first element of a tuple
type DropFirst<T extends unknown[]> = T extends [any, ...infer U] ? U : never;

// Prevent typos in translations
type TranslateType = (app: TranslateApps, ...args: DropFirst<Parameters<typeof t>>) => string;
type TranslatePluralType = (app: TranslateApps, ...args: DropFirst<Parameters<typeof n>>) => string;

export const translate = t as TranslateType;
export const translatePlural = n as TranslatePluralType;
