/**
 * Editor control state — single source of truth.
 *
 * Controls-only: this module describes *what* can be edited and the
 * default values. It must not apply any transform to an image.
 */

import { t } from '@services/l10n';

// --- Sections ---

export type EditorSection = 'adjust' | 'crop' | 'filters';

export type CropHandle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w';

// --- Adjust sliders ---

/** Editable numeric parameters (sliders). */
export interface EditorParams {
  brightness: number;
  exposure: number;
  contrast: number;
  highlights: number;
  shadows: number;
  saturation: number;
  vibrance: number;
  hue: number;
  temperature: number;
  blur: number;
  sharpen: number;
  vignette: number;
  straighten: number;
}

/** Key of any adjustable numeric parameter (slider). */
export type ParamKey = keyof EditorParams;

export interface SliderDef {
  key: ParamKey;
  min: number;
  max: number;
  /** default (reset) value */
  val: number;
  step?: number;
  /** gradient track modifier, e.g. "grad-warm" */
  cls?: string;
  group: 'light' | 'color' | 'detail' | 'crop';
}

export const SLIDER_DEFS: SliderDef[] = [
  { key: 'brightness', min: -100, max: 100, val: 0, group: 'light' },
  { key: 'exposure', min: -100, max: 100, val: 0, group: 'light' },
  { key: 'contrast', min: -100, max: 100, val: 0, group: 'light' },
  { key: 'highlights', min: -100, max: 100, val: 0, group: 'light' },
  { key: 'shadows', min: -100, max: 100, val: 0, group: 'light' },
  { key: 'saturation', min: -100, max: 100, val: 0, cls: 'grad-sat', group: 'color' },
  { key: 'vibrance', min: -100, max: 100, val: 0, group: 'color' },
  { key: 'hue', min: -180, max: 180, val: 0, cls: 'grad-hue', group: 'color' },
  { key: 'temperature', min: -100, max: 100, val: 0, cls: 'grad-warm', group: 'color' },
  { key: 'blur', min: 0, max: 100, val: 0, group: 'detail' },
  { key: 'sharpen', min: 0, max: 100, val: 0, group: 'detail' },
  { key: 'vignette', min: 0, max: 100, val: 0, group: 'detail' },
  { key: 'straighten', min: -45, max: 45, val: 0, step: 0.5, group: 'crop' },
];

export const PARAM_DEFS: Record<string, SliderDef> = Object.fromEntries(SLIDER_DEFS.map((d) => [d.key, d]));

/** Display name per adjust slider key. */
export const PARAM_NAMES: Record<ParamKey, string> = {
  brightness: t('memories', 'Brightness'),
  exposure: t('memories', 'Exposure'),
  contrast: t('memories', 'Contrast'),
  highlights: t('memories', 'Highlights'),
  shadows: t('memories', 'Shadows'),
  saturation: t('memories', 'Saturation'),
  vibrance: t('memories', 'Vibrance'),
  hue: t('memories', 'Hue'),
  temperature: t('memories', 'Temperature'),
  blur: t('memories', 'Blur'),
  sharpen: t('memories', 'Sharpen'),
  vignette: t('memories', 'Vignette'),
  straighten: t('memories', 'Straighten'),
};

export const DEFAULT_PARAMS: EditorParams = Object.fromEntries(
  SLIDER_DEFS.map((d) => [d.key, d.val]),
) as unknown as EditorParams;

export const ADJUST_KEYS = [
  'brightness',
  'exposure',
  'contrast',
  'highlights',
  'shadows',
  'saturation',
  'vibrance',
  'hue',
  'temperature',
  'blur',
  'sharpen',
  'vignette',
] as const;

// --- Filters ---

export type FilterGroup = 'base' | 'hot' | 'cold' | 'gray';

export interface FilterDef {
  id: keyof typeof FILTER_NAMES;
  name: string;
  group: FilterGroup;
}

/** Display name per filter preset id. */
export const FILTER_NAMES = {
  '': t('memories', 'None'),
  vivid: t('memories', 'Vivid'),
  bold: t('memories', 'Bold'),
  warm: t('memories', 'Warm'),
  vividWarm: t('memories', 'Vivid Warm'),
  sepia: t('memories', 'Sepia'),
  valencia: t('memories', 'Valencia'),
  boldWarm: t('memories', 'Bold Warm'),
  xpro2: t('memories', 'X-Pro II'),
  cool: t('memories', 'Cool'),
  vividCool: t('memories', 'Vivid Cool'),
  clarendon: t('memories', 'Clarendon'),
  lark: t('memories', 'Lark'),
  boldCool: t('memories', 'Bold Cool'),
  gingham: t('memories', 'Gingham'),
  mono: t('memories', 'Mono'),
  noir: t('memories', 'Noir'),
  silvertone: t('memories', 'Silvertone'),
  willow: t('memories', 'Willow'),
  fade: t('memories', 'Fade'),
  invert: t('memories', 'Negative'),
};

export const FILTER_GROUPS: FilterGroup[] = ['base', 'hot', 'cold', 'gray'];

export const FILTERS: FilterDef[] = [
  // Neutral starting point + gentle all-rounders.
  { id: '', name: FILTER_NAMES[''], group: 'base' },
  { id: 'vivid', name: FILTER_NAMES.vivid, group: 'base' },
  { id: 'bold', name: FILTER_NAMES.bold, group: 'base' },
  // Warm grades.
  { id: 'warm', name: FILTER_NAMES.warm, group: 'hot' },
  { id: 'vividWarm', name: FILTER_NAMES.vividWarm, group: 'hot' },
  { id: 'sepia', name: FILTER_NAMES.sepia, group: 'hot' },
  { id: 'valencia', name: FILTER_NAMES.valencia, group: 'hot' },
  { id: 'boldWarm', name: FILTER_NAMES.boldWarm, group: 'hot' },
  { id: 'xpro2', name: FILTER_NAMES.xpro2, group: 'hot' },
  // Cool grades.
  { id: 'cool', name: FILTER_NAMES.cool, group: 'cold' },
  { id: 'vividCool', name: FILTER_NAMES.vividCool, group: 'cold' },
  { id: 'clarendon', name: FILTER_NAMES.clarendon, group: 'cold' },
  { id: 'lark', name: FILTER_NAMES.lark, group: 'cold' },
  { id: 'boldCool', name: FILTER_NAMES.boldCool, group: 'cold' },
  { id: 'gingham', name: FILTER_NAMES.gingham, group: 'cold' },
  // Black & white, matte and special.
  { id: 'mono', name: FILTER_NAMES.mono, group: 'gray' },
  { id: 'noir', name: FILTER_NAMES.noir, group: 'gray' },
  { id: 'silvertone', name: FILTER_NAMES.silvertone, group: 'gray' },
  { id: 'willow', name: FILTER_NAMES.willow, group: 'gray' },
  { id: 'fade', name: FILTER_NAMES.fade, group: 'gray' },
  { id: 'invert', name: FILTER_NAMES.invert, group: 'gray' },
];

export function filtersInGroup(group: FilterGroup): FilterDef[] {
  return FILTERS.filter((f) => f.group === group);
}

// --- Crop ---

/** Crop ratio ids (keys of RATIO_NAMES). */
export type RatioId = keyof typeof RATIO_NAMES;

export interface RatioDef {
  id: RatioId;
  label: string;
}

/** Display name per crop ratio id. */
export const RATIO_NAMES = {
  free: t('memories', 'Free'),
  orig: t('memories', 'Original'),
  '1': '1:1',
  '1.333': '4:3',
  '1.5': '3:2',
  '1.778': '16:9',
  '0.8': '4:5',
  '0.5625': '9:16',
};

export const RATIOS: RatioDef[] = [
  { id: 'free', label: RATIO_NAMES.free },
  { id: 'orig', label: RATIO_NAMES.orig },
  { id: '1', label: RATIO_NAMES['1'] },
  { id: '1.333', label: RATIO_NAMES['1.333'] },
  { id: '1.5', label: RATIO_NAMES['1.5'] },
  { id: '1.778', label: RATIO_NAMES['1.778'] },
  { id: '0.8', label: RATIO_NAMES['0.8'] },
  { id: '0.5625', label: RATIO_NAMES['0.5625'] },
];

// --- State ---

/** Full edit state: params + discrete transform selections. No pixels here. */
export interface EditorState extends EditorParams {
  rotate: number;
  flipH: 1 | -1;
  flipV: 1 | -1;
  preset: string;
  ratio: string;
  comparing: boolean;
  cropX: number;
  cropY: number;
  cropW: number;
  cropH: number;
}

export const INITIAL_STATE: EditorState = {
  ...DEFAULT_PARAMS,
  rotate: 0,
  flipH: 1,
  flipV: 1,
  preset: '',
  ratio: 'free',
  comparing: false,
  cropX: 0,
  cropY: 0,
  cropW: 1,
  cropH: 1,
};

// --- Helpers ---

export function formatParamValue(key: string, v: number): string {
  if (key === 'straighten') return `${v > 0 ? '+' : ''}${v}°`;
  return `${Math.round(v)}`;
}
