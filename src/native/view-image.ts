import { NAPI, has, nativex } from './api';

/** Whether the native image view path is available. */
export function viewImageIsEnabled(): boolean {
  return has() && !!nativex.showNativeImage;
}

/** Monotonic upload counter; the native side ignores stale ones. */
let currentSeq = 0;

/** POST image bytes to the native view. Resolves after render. */
export async function viewImageUpload(blob: Blob): Promise<void> {
  const res = await fetch(NAPI.VIEW_IMAGE(++currentSeq), {
    method: 'POST',
    body: blob,
    headers: { 'Content-Type': blob.type || 'image/jpeg' },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`native image upload failed: ${res.status} ${text}`);
  }
}

/** POST the MediaStore URI to show the on-device copy. Resolves after render. */
export async function viewImageLocal(uri: string): Promise<void> {
  const res = await fetch(NAPI.VIEW_IMAGE_LOCAL(++currentSeq), {
    method: 'POST',
    body: JSON.stringify({ uri }),
    headers: { 'Content-Type': 'application/json' },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`native local image failed: ${res.status} ${text}`);
  }
}
