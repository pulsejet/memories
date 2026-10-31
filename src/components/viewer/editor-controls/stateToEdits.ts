import type { Edit } from '@radialapps/memories-vips-editor';

import { effectiveCrop, orientedDims } from './cropMath';
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
 * is flip, which has no vips edit yet. Those sliders remain live state for
 * a future backend transform.
 */

const P_EXPOSURE = 10;
const P_BRIGHTNESS_CONTRAST = 20;
const P_SHADOWS = 22;
const P_HIGHLIGHTS = 24;
const P_HUE_SATURATION = 30;
const P_WARMTH = 35;
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

  if (nonZero(state.shadows)) {
    ordered.push({
      priority: P_SHADOWS,
      edit: { edit: 'shadows', amount: state.shadows / 100 },
    });
  }

  if (nonZero(state.highlights)) {
    ordered.push({
      priority: P_HIGHLIGHTS,
      edit: { edit: 'highlights', amount: state.highlights / 100 },
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

  if (nonZero(state.temperature)) {
    ordered.push({
      priority: P_WARMTH,
      edit: { edit: 'warmth', amount: state.temperature / 100 },
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

/** Normalize an angle to (-180, 180], matching vips rotate. */
function normAngle(angle: number): number {
  let a = angle % 360;
  if (a <= -180) a += 360;
  else if (a > 180) a -= 360;
  // Avoid -0.
  return a === 0 ? 0 : a;
}

/**
 * Map an EditorState snapshot to the full vips save payload.
 *
 * Pixel edits come from stateToEdits (preview WYSIWYG). Geometry that vips
 * supports (rotate incl. straighten, crop) is appended after the pixels so
 * the backend renders `pixels -> rotate -> crop`, matching the editor where
 * the WebGL pixels are cropped/rotated by the CSS stage.
 *
 * Flip has no vips edit yet and is dropped for now (preview-only).
 * Comparing mode is ignored: saving always persists the actual edits.
 */
export function stateToSaveEdits(state: EditorState, natW: number, natH: number): Edit[] {
  const edits = stateToEdits(state);

  // Geometry needs real dimensions; without them only pixels can be saved.
  if (!(natW > 0 && natH > 0)) {
    return edits;
  }

  // Straighten folds into the rotate edit (single resample, no double band).
  const angle = normAngle((state.rotate || 0) + (state.straighten || 0));
  if (Math.abs(angle) > 1e-9) {
    edits.push({ edit: 'rotate', angle });
  }

  // Crop is stored center-based in oriented space, but the vips rotate
  // expands the canvas to the tilted bounding box and the crop runs after
  // it: fractions are relative to the rotated size, with the crop center
  // tilted by the straighten angle into that space.
  try {
    const { W, H } = orientedDims(natW, natH, state.rotate || 0);
    if (W > 0 && H > 0) {
      const geom = effectiveCrop(state, W, H);
      if (geom.w > 0 && geom.h > 0) {
        const rad = ((state.straighten || 0) * Math.PI) / 180;
        const c = Math.cos(rad);
        const s = Math.sin(rad);
        const wRot = W * Math.abs(c) + H * Math.abs(s);
        const hRot = W * Math.abs(s) + H * Math.abs(c);
        const uc = geom.px * c - geom.py * s;
        const vc = geom.px * s + geom.py * c;
        const x = (wRot / 2 + uc - geom.w / 2) / wRot;
        const y = (hRot / 2 + vc - geom.h / 2) / hRot;
        const width = geom.w / wRot;
        const height = geom.h / hRot;
        const clamp01 = (v: number) => Math.min(1, Math.max(0, v));
        const cx = clamp01(x);
        const cy = clamp01(y);
        const cw = clamp01(Math.min(width, 1 - cx));
        const ch = clamp01(Math.min(height, 1 - cy));
        const isFull = cx <= 1e-6 && cy <= 1e-6 && Math.abs(cw - 1) <= 1e-6 && Math.abs(ch - 1) <= 1e-6;
        if (!isFull && cw > 1e-9 && ch > 1e-9) {
          edits.push({ edit: 'crop', x: cx, y: cy, width: cw, height: ch });
        }
      }
    }
  } catch {
    // Geometry must never block pixel saves; skip crop on math errors.
  }

  return edits;
}
