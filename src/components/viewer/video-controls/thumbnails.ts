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
    if (w > 0 && h > 0) {
      Object.assign(thumb, { x, y, w, h });
    }
  }
  return thumb;
}

/** Attach a hidden metadata track so the browser parses the storyboard VTT. */
export function attachThumbnailTrack(video: HTMLVideoElement, vttUrl: string): HTMLTrackElement {
  const track = document.createElement('track');
  track.kind = 'metadata';
  track.src = vttUrl;
  video.appendChild(track);
  track.track.mode = 'hidden';
  return track;
}

/** Read parsed storyboard cues from a loaded metadata track. */
export function readThumbnailCues(track: HTMLTrackElement): ThumbnailCue[] {
  if (track.readyState !== HTMLTrackElement.LOADED || !track.track.cues) return [];
  const base = track.src;
  const cues: ThumbnailCue[] = [];
  for (const cue of Array.from(track.track.cues)) {
    if (!(cue instanceof VTTCue)) continue;
    const parsed = parseThumbnailText(cue.text, base);
    if (parsed) {
      cues.push({
        startTime: cue.startTime,
        endTime: cue.endTime,
        ...parsed,
      });
    }
  }
  return cues;
}

/** Active storyboard cue for a playback time, if any. */
export function findThumbnailCue(cues: ThumbnailCue[], time: number): ThumbnailCue | undefined {
  return cues.find((c) => time >= c.startTime && time < c.endTime);
}

/** Fetch and parse a storyboard VTT using the browser's own parser. */
export function loadThumbnailCues(
  video: HTMLVideoElement,
  vttUrl: string,
  signal?: AbortSignal,
): Promise<ThumbnailCue[]> {
  return new Promise((resolve) => {
    if (signal?.aborted) {
      resolve([]);
      return;
    }

    // Hidden metadata track: the browser fetches and parses the VTT.
    const track = attachThumbnailTrack(video, vttUrl);

    // Done with the element once cues are copied out (or loading failed).
    const done = (cues: ThumbnailCue[]) => {
      track.remove();
      resolve(cues);
    };
    track.addEventListener('load', () => done(readThumbnailCues(track)), { once: true });
    track.addEventListener('error', () => done([]), { once: true });

    // Aborted (URL changed or unmounted): drop the element, keep no cues.
    signal?.addEventListener('abort', () => done([]), { once: true });
  });
}
