import { NAPI, has, nativex } from './api';

/** Whether the native image view path is available. */
export function viewImageIsEnabled(): boolean {
  return has() && !!nativex.showNativeImage;
}

/** Monotonic upload counter; the native side ignores stale ones. */
let currentSeq = 0;

/** POST image bytes to the native view. Resolves after render; false if superseded. */
export async function viewImageUpload(blob: Blob): Promise<boolean> {
  const seq = ++currentSeq;
  const res = await fetch(NAPI.VIEW_IMAGE(seq), {
    method: 'POST',
    body: blob,
    headers: { 'Content-Type': blob.type || 'image/jpeg' },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`native image upload failed: ${res.status} ${text}`);
  }
  return isCurrent(res, seq);
}

/** POST the MediaStore URI to show the on-device copy. Resolves after render; false if superseded. */
export async function viewImageLocal(uri: string): Promise<boolean> {
  const seq = ++currentSeq;
  const res = await fetch(NAPI.VIEW_IMAGE_LOCAL(seq), {
    method: 'POST',
    body: JSON.stringify({ uri }),
    headers: { 'Content-Type': 'application/json' },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`native local image failed: ${res.status} ${text}`);
  }
  return isCurrent(res, seq);
}

/** Whether the native view rendered this upload and no newer one was issued. */
async function isCurrent(res: Response, seq: number): Promise<boolean> {
  const { ok } = await res.json().catch(() => ({ ok: false }));
  return !!ok && seq === currentSeq;
}
