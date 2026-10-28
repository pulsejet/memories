import { has, nativex } from './api';

/** Whether the native hole video path is available. */
export function viewVideoIsEnabled(): boolean {
  return has() && !!nativex.showNativeVideo;
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
