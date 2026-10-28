import { NAPI, has, nativex } from './api';

/** Whether the native hole video path is available. */
export function viewVideoIsEnabled(): boolean {
  return has() && !!nativex.showNativeVideo;
}

/** Monotonic upload counter; the native side ignores stale ones. */
let currentSeq = 0;

/**
 * Point the native hole video at a URL and start playback immediately.
 * Resolves at once without waiting for the first frame.
 */
export async function viewVideoUrl(url: string): Promise<void> {
  const res = await fetch(NAPI.VIEW_VIDEO(++currentSeq), {
    method: 'POST',
    body: JSON.stringify({ url }),
    headers: { 'Content-Type': 'application/json' },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`native hole video failed: ${res.status} ${text}`);
  }
}

// ExoPlayer codecs, queried once. Falls back to h264 when falsy.
let cachedCodecs: string[] | undefined;

/** Codecs to request from go-vod for the native hole player. */
export function getNativePlayableCodecsSync(): string[] {
  try {
    return (cachedCodecs ??= JSON.parse(nativex?.getPlayableVideoCodecs?.() ?? 'null') || ['h264']);
  } catch {
    return (cachedCodecs ??= ['h264']);
  }
}
