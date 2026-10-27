import { getCanonicalLocale } from '@services/l10n';

const digits1 = new Intl.NumberFormat(getCanonicalLocale(), { useGrouping: false });
const digits2 = new Intl.NumberFormat(getCanonicalLocale(), { useGrouping: false, minimumIntegerDigits: 2 });

export function clamp01(v: number) {
  return Math.min(1, Math.max(0, v));
}

export function formatMediaTime(sec: number, showHours: boolean) {
  if (!Number.isFinite(sec) || sec < 0) {
    sec = 0;
  }
  sec = Math.floor(sec);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (showHours || h > 0) {
    return `${digits1.format(h)}:${digits2.format(m)}:${digits2.format(s)}`;
  }
  return `${digits1.format(m)}:${digits2.format(s)}`;
}
