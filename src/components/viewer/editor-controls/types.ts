/**
 * Editor control state — single source of truth.
 *
 * Controls-only: this module describes *what* can be edited and the
 * default values. It must not apply any transform to an image.
 */

export type EditorSection = 'adjust' | 'crop' | 'filters';

export type CropHandle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w';

export interface SliderDef {
  key: string;
  min: number;
  max: number;
  /** default (reset) value */
  val: number;
  step?: number;
  /** gradient track modifier, e.g. "grad-warm" */
  cls?: string;
  group: 'light' | 'color' | 'detail' | 'crop';
}

export type FilterGroup = 'base' | 'hot' | 'cold' | 'gray';

export interface FilterDef {
  id: string;
  name: string;
  group: FilterGroup;
}

export interface RatioDef {
  id: string;
  label: string;
}

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

export const DEFAULT_PARAMS: EditorParams = Object.fromEntries(
  SLIDER_DEFS.map((d) => [d.key, d.val]),
) as unknown as EditorParams;

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

export const FILTER_GROUPS: FilterGroup[] = ['base', 'hot', 'cold', 'gray'];

export const FILTERS: FilterDef[] = [
  // Neutral starting point + gentle all-rounders.
  { id: '', name: 'None', group: 'base' },
  { id: 'vivid', name: 'Vivid', group: 'base' },
  { id: 'bold', name: 'Bold', group: 'base' },
  // Warm grades.
  { id: 'warm', name: 'Warm', group: 'hot' },
  { id: 'vividWarm', name: 'Vivid Warm', group: 'hot' },
  { id: 'sepia', name: 'Sepia', group: 'hot' },
  { id: 'valencia', name: 'Valencia', group: 'hot' },
  { id: 'boldWarm', name: 'Bold Warm', group: 'hot' },
  { id: 'xpro2', name: 'X-Pro II', group: 'hot' },
  // Cool grades.
  { id: 'cool', name: 'Cool', group: 'cold' },
  { id: 'vividCool', name: 'Vivid Cool', group: 'cold' },
  { id: 'clarendon', name: 'Clarendon', group: 'cold' },
  { id: 'lark', name: 'Lark', group: 'cold' },
  { id: 'boldCool', name: 'Bold Cool', group: 'cold' },
  { id: 'gingham', name: 'Gingham', group: 'cold' },
  // Black & white, matte and special.
  { id: 'mono', name: 'Mono', group: 'gray' },
  { id: 'noir', name: 'Noir', group: 'gray' },
  { id: 'silvertone', name: 'Silvertone', group: 'gray' },
  { id: 'willow', name: 'Willow', group: 'gray' },
  { id: 'fade', name: 'Fade', group: 'gray' },
  { id: 'invert', name: 'Negative', group: 'gray' },
];

export function filtersInGroup(group: FilterGroup): FilterDef[] {
  return FILTERS.filter((f) => f.group === group);
}

export const RATIOS: RatioDef[] = [
  { id: 'free', label: 'Free' },
  { id: 'orig', label: 'Original' },
  { id: '1', label: '1:1' },
  { id: '1.333', label: '4:3' },
  { id: '1.5', label: '3:2' },
  { id: '1.778', label: '16:9' },
  { id: '0.8', label: '4:5' },
  { id: '0.5625', label: '9:16' },
];

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

export function formatParamValue(key: string, v: number): string {
  if (key === 'straighten') return `${v > 0 ? '+' : ''}${v}°`;
  return `${Math.round(v)}`;
}

export function capitalize(key: string): string {
  return key.charAt(0).toUpperCase() + key.slice(1);
}
