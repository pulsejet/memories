import type { Edit } from '@radialapps/memories-vips-editor';

import type { EditorState } from './types';

/**
 * Translate EditorControls state into an ordered vips edit list.
 *
 * Each entry carries a render-order priority; the list is always sorted by
 * it so the pipeline (and the VIPS backend consuming the same JSON) sees a
 * deterministic order no matter which control changed: exposure-style gain
 * first, then tonal, then color, then creative preset grades.
 *
 * Only parameters with a clean VIPS mapping in
 * @radialapps/memories-vips-editor are translated. Rotate / crop members
 * (rotate, flipH/V, zoom, ratio, straighten) are intentionally excluded, as
 * are highlights / shadows / temperature, which need curves or white-balance
 * support that the library does not provide yet (see its README excluded
 * list). Those sliders remain live state for a future backend transform.
 */

const P_EXPOSURE = 10;
const P_BRIGHTNESS_CONTRAST = 20;
const P_HUE_SATURATION = 30;
const P_VIBRANCE = 40;
const P_PRESET = 50;

function nonZero(v: number): boolean {
  return Math.abs(v) > 1e-9;
}

/** Creative preset grades, expressed with currently available edits. */
function presetEdits(preset: string): Edit[] {
  switch (preset) {
    case 'vivid':
      return [
        { edit: 'brightnessContrast', contrast: 0.18 },
        { edit: 'hueSaturation', saturation: 0.3 },
        { edit: 'vibrance', amount: 0.2 },
      ];
    case 'warm':
      return [
        { edit: 'sepia', amount: 0.35 },
        { edit: 'hueSaturation', saturation: 0.1 },
      ];
    case 'cool':
      return [
        { edit: 'exposure', stops: 0.15 },
        { edit: 'hueSaturation', hue: 0.05, saturation: 0.06 },
      ];
    case 'fade':
      return [
        { edit: 'brightnessContrast', brightness: 0.08, contrast: -0.22 },
        { edit: 'hueSaturation', saturation: -0.3 },
      ];
    case 'mono':
      return [{ edit: 'grayscale' }];
    case 'noir':
      return [{ edit: 'grayscale' }, { edit: 'brightnessContrast', brightness: -0.03, contrast: 0.3 }];
    default:
      return [];
  }
}

/**
 * Map an EditorState snapshot to a priority-sorted edit list.
 * The default (untouched) state maps to an empty list (original image).
 */
export function stateToEdits(state: EditorState): Edit[] {
  const ordered: { priority: number; edit: Edit }[] = [];

  if (nonZero(state.exposure)) {
    ordered.push({
      priority: P_EXPOSURE,
      edit: { edit: 'exposure', stops: state.exposure / 20 },
    });
  }

  if (nonZero(state.brightness) || nonZero(state.contrast)) {
    ordered.push({
      priority: P_BRIGHTNESS_CONTRAST,
      edit: {
        edit: 'brightnessContrast',
        brightness: state.brightness / 100,
        contrast: state.contrast / 100,
      },
    });
  }

  if (nonZero(state.hue) || nonZero(state.saturation)) {
    ordered.push({
      priority: P_HUE_SATURATION,
      edit: {
        edit: 'hueSaturation',
        hue: state.hue / 180,
        saturation: state.saturation / 100,
      },
    });
  }

  if (nonZero(state.vibrance)) {
    ordered.push({
      priority: P_VIBRANCE,
      edit: { edit: 'vibrance', amount: state.vibrance / 100 },
    });
  }

  for (const [i, edit] of presetEdits(state.preset).entries()) {
    ordered.push({ priority: P_PRESET + i, edit });
  }

  ordered.sort((a, b) => a.priority - b.priority);
  return ordered.map((o) => o.edit);
}
