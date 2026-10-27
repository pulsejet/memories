import { WebVTTParser } from 'webvtt-parser';

/** Single storyboard thumbnail cue parsed from a VTT file. */
export interface ThumbnailCue {
  startTime: number;
  endTime: number;
  url: string;
  x?: number;
  y?: number;
  w?: number;
  h?: number;
}

function parseThumbnailText(text: string, base: string): Omit<ThumbnailCue, 'startTime' | 'endTime'> | null {
  const [rawUrl, hash] = text.trim().split('#');
  if (!rawUrl) return null;
  let url: string;
  try {
    url = new URL(rawUrl, base).href;
  } catch {
    return null;
  }
  const thumb: Omit<ThumbnailCue, 'startTime' | 'endTime'> = { url };
  const m = hash?.match(/^xywh=(\d+),(\d+),(\d+),(\d+)$/);
  if (m) {
    const [, x, y, w, h] = m.map(Number);
    if (w > 0 && h > 0) Object.assign(thumb, { x, y, w, h });
  }
  return thumb;
}

/** Fetch and parse a storyboard VTT with the W3C reference parser. */
export async function loadThumbnailCues(vttUrl: string, signal?: AbortSignal): Promise<ThumbnailCue[]> {
  try {
    const res = await fetch(vttUrl, { signal });
    if (!res.ok) return [];
    const { cues } = new WebVTTParser().parse(await res.text(), 'metadata');
    return cues.flatMap((cue) => {
      const parsed = parseThumbnailText(cue.text, vttUrl);
      return parsed ? [{ startTime: cue.startTime, endTime: cue.endTime, ...parsed }] : [];
    });
  } catch {
    // Aborted or unloadable: fall back to the time-only tooltip.
    return [];
  }
}

/** Active storyboard cue for a playback time, if any. */
export function findThumbnailCue(cues: ThumbnailCue[], time: number): ThumbnailCue | undefined {
  return cues.find((c) => time >= c.startTime && time < c.endTime);
}
