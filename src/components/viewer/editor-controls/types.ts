/**
 * Editor control state — single source of truth.
 *
 * Controls-only: this module describes *what* can be edited and the
 * default values. It must not apply any transform to an image.
 */

export type EditorSection = 'adjust' | 'crop' | 'filters';

export interface SliderDef {
  key: string;
  min: number;
  max: number;
  /** default (reset) value */
  val: number;
  step?: number;
  /** gradient track modifier, e.g. "grad-warm" */
  cls?: string;
  group: 'light' | 'color' | 'crop';
}

export interface FilterDef {
  id: string;
  name: string;
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
  straighten: number;
}

/** Full edit state: params + discrete transform selections. No pixels here. */
export interface EditorState extends EditorParams {
  rotate: number;
  flipH: 1 | -1;
  flipV: 1 | -1;
  zoom: number;
  preset: string;
  ratio: string;
  comparing: boolean;
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
  zoom: 1,
  preset: '',
  ratio: 'free',
  comparing: false,
};

export const FILTERS: FilterDef[] = [
  { id: '', name: 'None' },
  { id: 'vivid', name: 'Vivid' },
  { id: 'warm', name: 'Warm' },
  { id: 'cool', name: 'Cool' },
  { id: 'fade', name: 'Fade' },
  { id: 'mono', name: 'Mono' },
  { id: 'noir', name: 'Noir' },
];

export const RATIOS: RatioDef[] = [
  { id: 'free', label: 'Free' },
  { id: 'orig', label: 'Orig' },
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
] as const;

export function formatParamValue(key: string, v: number): string {
  if (key === 'straighten') return `${v > 0 ? '+' : ''}${v}°`;
  return `${Math.round(v)}`;
}

export function capitalize(key: string): string {
  return key.charAt(0).toUpperCase() + key.slice(1);
}
