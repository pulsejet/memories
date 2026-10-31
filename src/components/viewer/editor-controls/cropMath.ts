import type { CropHandle, EditorState } from './types';

export interface CropGeometry {
  px: number;
  py: number;
  w: number;
  h: number;
}

export interface HandleDragContext {
  handle: CropHandle;
  startClientX: number;
  startClientY: number;
  startScale: number;
  startW: number;
  startH: number;
  uMin0: number;
  uMax0: number;
  vMin0: number;
  vMax0: number;
  W: number;
  H: number;
  straightenDeg: number;
  ratio: number | null;
}

export interface HandleDragResult {
  cropX: number;
  cropY: number;
  cropW: number;
  cropH: number;
  px: number;
  py: number;
  scaleDrag: number;
  boxX: number;
  boxY: number;
  boxW: number;
  boxH: number;
  boostDistX: number;
  boostDistY: number;
  maxBoostZoomX: number;
  maxBoostZoomY: number;
  canExpandX: boolean;
  canExpandY: boolean;
}

function clamp(v: number, lo: number, hi: number): number {
  return Math.min(hi, Math.max(lo, v));
}

export function orientedDims(natW: number, natH: number, rotateDeg: number): { W: number; H: number } {
  const q = ((Math.round(rotateDeg / 90) % 4) + 4) % 4;
  return q % 2 === 1 ? { W: natH, H: natW } : { W: natW, H: natH };
}

export function parseRatio(ratioId: string, W: number, H: number, inverted = false): number | null {
  if (!ratioId || ratioId === 'free') return null;
  let r: number;
  if (ratioId === 'orig') {
    r = W / H;
  } else if (ratioId === '1.333') {
    r = 4 / 3;
  } else if (ratioId === '1.778') {
    r = 16 / 9;
  } else {
    r = parseFloat(ratioId);
    if (!Number.isFinite(r) || r <= 0) return null;
  }
  return inverted ? 1 / r : r;
}

export function projectedHalfExtents(w: number, h: number, cosAbs: number, sinAbs: number): { dx: number; dy: number } {
  return {
    dx: 0.5 * (w * cosAbs + h * sinAbs),
    dy: 0.5 * (w * sinAbs + h * cosAbs),
  };
}

/** Auto-zoom factor keeping a tilted `(w0 x h0)` crop centered at `(px, py)` inside `(W x H)`. */
export function tiltZoom(
  w0: number,
  h0: number,
  px: number,
  py: number,
  W: number,
  H: number,
  straightenDeg: number,
): number {
  const rad = (straightenDeg * Math.PI) / 180;
  const cosAbs = Math.abs(Math.cos(rad));
  const sinAbs = Math.abs(Math.sin(rad));
  const availX = Math.max(1e-3, W / 2 - Math.abs(px));
  const availY = Math.max(1e-3, H / 2 - Math.abs(py));
  const { dx, dy } = projectedHalfExtents(w0, h0, cosAbs, sinAbs);
  return Math.max(1, dx / availX, dy / availY);
}

export function effectiveCrop(state: EditorState, W: number, H: number): CropGeometry {
  const w0 = clamp(state.cropW, 0.02, 1) * W;
  const h0 = clamp(state.cropH, 0.02, 1) * H;
  const maxPx0 = Math.max(0, W / 2 - 1);
  const maxPy0 = Math.max(0, H / 2 - 1);
  const px = clamp(state.cropX * W, -maxPx0, maxPx0);
  const py = clamp(state.cropY * H, -maxPy0, maxPy0);
  const zoom = tiltZoom(w0, h0, px, py, W, H, state.straighten);
  return {
    px,
    py,
    w: w0 / zoom,
    h: h0 / zoom,
  };
}

export function commitVisibleCrop(
  px: number,
  py: number,
  w: number,
  h: number,
  W: number,
  H: number,
  straightenDeg: number,
): Pick<EditorState, 'cropX' | 'cropY' | 'cropW' | 'cropH'> {
  const rad = (straightenDeg * Math.PI) / 180;
  const cosAbs = Math.abs(Math.cos(rad));
  const sinAbs = Math.abs(Math.sin(rad));
  const availX = Math.max(1e-3, W / 2 - Math.abs(px));
  const availY = Math.max(1e-3, H / 2 - Math.abs(py));
  const { dx, dy } = projectedHalfExtents(w, h, cosAbs, sinAbs);
  const rTilt = Math.max(dx / availX, dy / availY);

  let m = 1;
  if (rTilt >= 0.999 && sinAbs > 1e-6) {
    const mMax = Math.min((2 * availX) / w, (2 * availY) / h);
    m = Math.max(1, mMax * rTilt);
  }

  return {
    cropX: clamp(px / W, -0.5, 0.5),
    cropY: clamp(py / H, -0.5, 0.5),
    cropW: clamp((w * m) / W, 0.02, 1),
    cropH: clamp((h * m) / H, 0.02, 1),
  };
}

export function clampPan(
  px: number,
  py: number,
  w: number,
  h: number,
  W: number,
  H: number,
  straightenDeg: number,
): { px: number; py: number } {
  const rad = (straightenDeg * Math.PI) / 180;
  const { dx, dy } = projectedHalfExtents(w, h, Math.abs(Math.cos(rad)), Math.abs(Math.sin(rad)));
  const maxPx = Math.max(0, W / 2 - dx);
  const maxPy = Math.max(0, H / 2 - dy);
  return {
    px: clamp(px, -maxPx, maxPx),
    py: clamp(py, -maxPy, maxPy),
  };
}

export function applyPanDelta(
  startPx: number,
  startPy: number,
  w: number,
  h: number,
  screenDx: number,
  screenDy: number,
  scaleScreen: number,
  W: number,
  H: number,
  straightenDeg: number,
): Pick<EditorState, 'cropX' | 'cropY' | 'cropW' | 'cropH'> {
  const du = -screenDx / scaleScreen;
  const dv = -screenDy / scaleScreen;
  const rad = (straightenDeg * Math.PI) / 180;
  const c = Math.cos(rad);
  const s = Math.sin(rad);
  const rawPx = startPx + du * c + dv * s;
  const rawPy = -du * s + dv * c + startPy;
  const clamped = clampPan(rawPx, rawPy, w, h, W, H, straightenDeg);
  return commitVisibleCrop(clamped.px, clamped.py, w, h, W, H, straightenDeg);
}

export function fitAspectRatio(
  ratio: number,
  px: number,
  py: number,
  W: number,
  H: number,
  straightenDeg: number,
): Pick<EditorState, 'cropX' | 'cropY' | 'cropW' | 'cropH'> {
  const w0 = W / H > ratio ? H * ratio : W;
  const h0 = W / H > ratio ? H : W / ratio;
  const z = tiltZoom(w0, h0, 0, 0, W, H, straightenDeg);
  const clamped = clampPan(px, py, w0 / z, h0 / z, W, H, straightenDeg);
  return {
    cropX: clamped.px / W,
    cropY: clamped.py / H,
    cropW: w0 / W,
    cropH: h0 / H,
  };
}

export function rotateCrop90(
  state: EditorState,
  dir: 1 | -1,
): Pick<EditorState, 'cropX' | 'cropY' | 'cropW' | 'cropH'> {
  return {
    cropX: dir > 0 ? -state.cropY : state.cropY,
    cropY: dir > 0 ? state.cropX : -state.cropX,
    cropW: state.cropH,
    cropH: state.cropW,
  };
}

export function solveHandleDrag(
  ctx: HandleDragContext,
  clientX: number,
  clientY: number,
  boostZoomX = 1,
  boostZoomY = 1,
): HandleDragResult {
  const {
    handle,
    startClientX,
    startClientY,
    startScale,
    startW,
    startH,
    uMin0,
    uMax0,
    vMin0,
    vMax0,
    W,
    H,
    straightenDeg,
    ratio,
  } = ctx;

  const screenDx = clientX - startClientX;
  const screenDy = clientY - startClientY;
  const su = handle.includes('e') ? 1 : handle.includes('w') ? -1 : 0;
  const sv = handle.includes('s') ? 1 : handle.includes('n') ? -1 : 0;

  const outX = su * screenDx;
  const outY = sv * screenDy;

  const sw0 = startW * startScale;
  const sh0 = startH * startScale;
  const minScreen = Math.max(24, Math.min(sw0, sh0) * 0.08);

  const rad = (straightenDeg * Math.PI) / 180;
  const c = Math.cos(rad);
  const s = Math.sin(rad);
  const cosAbs = Math.abs(c);
  const sinAbs = Math.abs(s);

  const uc0 = 0.5 * (uMin0 + uMax0);
  const vc0 = 0.5 * (vMin0 + vMax0);
  const px0 = uc0 * c + vc0 * s;
  const py0 = -uc0 * s + vc0 * c;
  const { dx: dx0, dy: dy0 } = projectedHalfExtents(startW, startH, cosAbs, sinAbs);

  const a = [0.5 * (cosAbs + su * c), 0.5 * (cosAbs - su * c), 0.5 * (sinAbs - su * s), 0.5 * (sinAbs + su * s)];
  const b = [0.5 * (sinAbs + sv * s), 0.5 * (sinAbs - sv * s), 0.5 * (cosAbs + sv * c), 0.5 * (cosAbs - sv * c)];
  const rhs = [
    Math.max(0, W / 2 - (px0 + dx0)),
    Math.max(0, W / 2 - (-px0 + dx0)),
    Math.max(0, H / 2 - (py0 + dy0)),
    Math.max(0, H / 2 - (-py0 + dy0)),
  ];

  const maxBoostFor = (maxDim: number, startDim: number, outPx: number, otherZoom: number) => {
    const zAtOther = (maxDim - (outPx / startScale) * otherZoom) / startDim;
    if (zAtOther <= otherZoom) return Math.max(1, zAtOther);
    return Math.max(1, maxDim / Math.max(1, startDim + outPx / startScale));
  };

  let w: number;
  let h: number;
  let scaleDrag: number;
  let boostDistX = 0;
  let boostDistY = 0;
  let maxBoostZoomX = 1;
  let maxBoostZoomY = 1;
  let canExpandX = false;
  let canExpandY = false;
  let zX = Math.max(1, boostZoomX);
  let zY = Math.max(1, boostZoomY);

  if (ratio !== null) {
    const effOutX = su !== 0 && sv !== 0 ? 0.5 * (outX + outY * ratio) : su !== 0 ? outX : outY * ratio;

    let maxDh = Infinity;
    for (let i = 0; i < 4; i++) {
      const denom = a[i] * ratio + b[i];
      if (denom > 1e-6) maxDh = Math.min(maxDh, rhs[i] / denom);
    }
    const hMax = startH + Math.max(0, maxDh);
    const wMax = hMax * ratio;

    const z = Math.max(zX, zY);
    zX = z;
    zY = z;
    scaleDrag = startScale / z;

    const minW = Math.max(minScreen, minScreen * ratio) / scaleDrag;
    const wRaw = startW * z + effOutX / scaleDrag;
    w = clamp(wRaw, Math.min(minW, wMax), wMax);
    h = w / ratio;

    const maxZ = Math.max(1, wMax / Math.max(1, startW + effOutX / startScale));
    maxBoostZoomX = maxZ;
    maxBoostZoomY = maxZ;
    boostDistX = effOutX;
    boostDistY = effOutX / ratio;
    canExpandX = effOutX > 2 && z < maxZ - 1e-4 && w < wMax - 1e-3;
    canExpandY = canExpandX;
  } else {
    scaleDrag = startScale / Math.max(zX, zY);
    const minW = minScreen / scaleDrag;
    const minH = minScreen / scaleDrag;

    const actOutX = su !== 0 ? outX : 0;
    const actOutY = sv !== 0 ? outY : 0;
    const w1 = su !== 0 ? Math.max(minW, startW * zX + actOutX / scaleDrag) : startW;
    const h1 = sv !== 0 ? Math.max(minH, startH * zY + actOutY / scaleDrag) : startH;

    let dw = w1 - startW;
    let dh = h1 - startH;

    if (dw > 0 && dh > 0) {
      let t = 1;
      for (let i = 0; i < 4; i++) {
        const lhs = a[i] * dw + b[i] * dh;
        if (lhs > rhs[i] && lhs > 1e-6) {
          t = Math.min(t, rhs[i] / lhs);
        }
      }
      const dw0 = t * dw;
      const dh0 = t * dh;
      let remW = dw - dw0;
      let remH = dh - dh0;
      for (let i = 0; i < 4; i++) {
        const remRhs = Math.max(0, rhs[i] - a[i] * dw0 - b[i] * dh0);
        if (a[i] > 1e-6) remW = Math.min(remW, remRhs / a[i]);
        if (b[i] > 1e-6) remH = Math.min(remH, remRhs / b[i]);
      }
      dw = dw0 + Math.max(0, remW);
      dh = dh0 + Math.max(0, remH);
    } else if (dw > 0) {
      for (let i = 0; i < 4; i++) {
        if (a[i] > 1e-6) dw = Math.min(dw, Math.max(0, (rhs[i] - b[i] * dh) / a[i]));
      }
    } else if (dh > 0) {
      for (let i = 0; i < 4; i++) {
        if (b[i] > 1e-6) dh = Math.min(dh, Math.max(0, (rhs[i] - a[i] * dw) / b[i]));
      }
    }

    w = Math.max(1, startW + dw);
    h = Math.max(1, startH + dh);

    let maxDw = Infinity;
    let maxDh = Infinity;
    for (let i = 0; i < 4; i++) {
      if (a[i] > 1e-6) maxDw = Math.min(maxDw, Math.max(0, (rhs[i] - b[i] * dh) / a[i]));
      if (b[i] > 1e-6) maxDh = Math.min(maxDh, Math.max(0, (rhs[i] - a[i] * dw) / b[i]));
    }
    const wMax = startW + (Number.isFinite(maxDw) ? maxDw : 0);
    const hMax = startH + (Number.isFinite(maxDh) ? maxDh : 0);

    maxBoostZoomX = su !== 0 ? maxBoostFor(wMax, startW, actOutX, zY) : 1;
    maxBoostZoomY = sv !== 0 ? maxBoostFor(hMax, startH, actOutY, zX) : 1;
    boostDistX = actOutX;
    boostDistY = actOutY;
    canExpandX = su !== 0 && actOutX > 2 && zX < maxBoostZoomX - 1e-4 && w < wMax - 1e-3;
    canExpandY = sv !== 0 && actOutY > 2 && zY < maxBoostZoomY - 1e-4 && h < hMax - 1e-3;
  }

  const uc = su > 0 ? uMin0 + w / 2 : su < 0 ? uMax0 - w / 2 : uc0;
  const vc = sv > 0 ? vMin0 + h / 2 : sv < 0 ? vMax0 - h / 2 : vc0;

  const rawPx = uc * c + vc * s;
  const rawPy = -uc * s + vc * c;
  const clamped = clampPan(rawPx, rawPy, w, h, W, H, straightenDeg);

  const boxW = w * scaleDrag;
  const boxH = h * scaleDrag;
  const boxX = su * (0.5 * boxW + 0.5 * sw0 - startW * zX * scaleDrag);
  const boxY = sv * (0.5 * boxH + 0.5 * sh0 - startH * zY * scaleDrag);

  const committed = commitVisibleCrop(clamped.px, clamped.py, w, h, W, H, straightenDeg);
  return {
    ...committed,
    px: clamped.px,
    py: clamped.py,
    scaleDrag,
    boxX,
    boxY,
    boxW,
    boxH,
    boostDistX,
    boostDistY,
    maxBoostZoomX,
    maxBoostZoomY,
    canExpandX,
    canExpandY,
  };
}
