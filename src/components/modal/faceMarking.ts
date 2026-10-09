import { translate as t, translatePlural as n } from '@services/l10n';

import type { IFaceLimits, IFaceRectForFile, IManualRegion } from '@services/dav/face';

/** A rectangle as fractions 0..1 of the photo. */
export type Rect = { x: number; y: number; w: number; h: number };

/** A face as the stage draws it. */
export type StageFace = {
  id: number;
  rect: Rect;
  classes: string[];
  title: string;
  label: string;
};

/** A region as the stage draws it. */
export type StageRegion = {
  id: number;
  rect: Rect;
  classes: string[];
  title: string;
};

export function toCss(r: Rect): Record<string, string> {
  return {
    left: `${r.x * 100}%`,
    top: `${r.y * 100}%`,
    width: `${r.w * 100}%`,
    height: `${r.h * 100}%`,
  };
}

export function rectFromPoints(a: { x: number; y: number }, b: { x: number; y: number }): Rect {
  return {
    x: Math.min(a.x, b.x),
    y: Math.min(a.y, b.y),
    w: Math.abs(a.x - b.x),
    h: Math.abs(a.y - b.y),
  };
}

/** A rectangle in pixels of the photo as fractions of it. */
export function fractionsOf(
  box: { x: number; y: number; width: number; height: number },
  width: number,
  height: number,
): Rect {
  return { x: box.x / width, y: box.y / height, w: box.width / width, h: box.height / height };
}

export type FaceOriginLook = 'auto' | 'manual' | 'unknown';
export type FaceClusteringLook = 'participating' | 'pending' | 'excluded' | 'none' | 'unknown';

/**
 * Where a face came from. The server derives it from the state of the face;
 * an older server does not, and then the manual flag is all there is.
 */
export function originOf(face: IFaceRectForFile): FaceOriginLook {
  if (face.origin === null) return face.isManual ? 'manual' : 'auto';
  if (face.origin === 'auto' || face.origin === 'manual') return face.origin;
  return 'unknown';
}

/** Whether a face takes part in the clustering; 'none' when the server does not say. */
export function clusteringOf(face: IFaceRectForFile): FaceClusteringLook {
  if (face.clustering === null) return 'none';
  if (face.clustering === 'participating' || face.clustering === 'pending' || face.clustering === 'excluded') {
    return face.clustering;
  }
  return 'unknown';
}

/** Whether the user ignored the face: it is nobody, and takes no part in the recognition. */
export function isIgnored(face: IFaceRectForFile): boolean {
  return face.ignored === true || face.excludedReason === 'ignored';
}

/**
 * Whether the face can be deleted: only what was put there by hand, a marking
 * or a face found in a searched area. A face of the analysis would come back
 * with its next analysis of the photo, so it can only be ignored.
 */
export function canDelete(face: IFaceRectForFile): boolean {
  return originOf(face) === 'manual';
}

export function nameOf(face: IFaceRectForFile): string {
  if (isIgnored(face)) return t('memories', 'Ignored face');
  return face.personName || t('memories', 'Unnamed person');
}

/** Where the face came from, in words. */
export function originText(face: IFaceRectForFile): string {
  switch (originOf(face)) {
    case 'manual':
      return t('memories', 'Marked by hand.');
    case 'unknown':
      return t('memories', 'Origin unknown ({origin}).', { origin: String(face.origin) });
    default:
      return face.manualState === 'confirmed'
        ? t(
            'memories',
            'Found by the automatic analysis. It was marked by hand first, and the analysis has found it by itself since.',
          )
        : t('memories', 'Found by the automatic analysis.');
  }
}

/** Whether the face takes part in the automatic recognition, and why not, in words. */
export function participationText(face: IFaceRectForFile, limits: IFaceLimits | null): string {
  switch (clusteringOf(face)) {
    case 'none':
      return '';
    case 'participating':
      return t('memories', 'Used for the automatic recognition.');
    case 'pending':
      return t('memories', 'Waiting for the face recognition: the marked area is searched for a face on its next run.');
    case 'unknown':
      return t('memories', 'Unknown state ({state}).', { state: String(face.clustering) });
  }

  switch (face.excludedReason) {
    case 'too_small':
      return limits
        ? t('memories', 'Not used for the automatic recognition: too small ({size} px, the minimum is {min} px).', {
            size: Math.min(face.width, face.height),
            min: limits.minFaceSize,
          })
        : t('memories', 'Not used for the automatic recognition: too small.');
    case 'low_confidence':
      return limits && face.confidence !== null
        ? t(
            'memories',
            'A face was found here, but the recognition does not trust it enough to assign it automatically (confidence {value}, the threshold is {min}). You can still name it by hand.',
            { value: face.confidence.toFixed(2), min: limits.minConfidence.toFixed(2) },
          )
        : t(
            'memories',
            'A face was found here, but the recognition does not trust it enough to assign it automatically. You can still name it by hand.',
          );
    case 'no_face':
      return t(
        'memories',
        'No face was recognized in the marked area. The marking stays, but it is not used for the automatic recognition.',
      );
    case 'detached':
      return t('memories', 'Assigned to a person by hand, so the automatic recognition leaves it alone.');
    case 'ignored':
      return t(
        'memories',
        'Ignored: it is nobody, it does not show among the people, and the automatic recognition leaves it alone.',
      );
    default:
      return t('memories', 'Not used for the automatic recognition (unknown reason: {reason}).', {
        reason: String(face.excludedReason),
      });
  }
}

/** Further things worth knowing about a face, in words. */
export function hintsOf(face: IFaceRectForFile): string[] {
  const hints: string[] = [];
  if (face.boxAdjusted && originOf(face) === 'manual') {
    hints.push(
      t(
        'memories',
        'The recognition put the box somewhere else than where it was drawn. If it is not on the face you meant, mark that face again.',
      ),
    );
  }
  return hints;
}

/** What a face is, on one line, for when the pointer rests on it. */
export function titleOf(face: IFaceRectForFile, limits: IFaceLimits | null): string {
  return [nameOf(face), originText(face), participationText(face, limits), ...hintsOf(face)]
    .filter((part) => !!part)
    .join(' — ');
}

export function stageFaceOf(
  face: IFaceRectForFile,
  width: number,
  height: number,
  limits: IFaceLimits | null,
  selected: boolean,
): StageFace {
  const origin = originOf(face);
  const ignored = isIgnored(face);
  return {
    id: face.id,
    rect: fractionsOf(face, width, height),
    classes: [
      'existing',
      `origin-${origin}`,
      `clustering-${clusteringOf(face)}`,
      ...(ignored ? ['ignored'] : []),
      ...(selected ? ['selected'] : []),
    ],
    title: titleOf(face, limits),
    // An ignored face is only a faint box, without a label that would clutter
    // the photo when there are many of them. Marked by hand shows without
    // colours too.
    label: ignored ? '' : (origin === 'manual' ? '✎ ' : '') + (face.personName || '?'),
  };
}

/** What came out of the search of a region, in words. */
export function regionText(region: IManualRegion): string {
  switch (region.state) {
    case 'pending':
      return t('memories', 'Waiting: this area is searched for faces on the next run of the face recognition.');
    case 'failed':
      return t('memories', 'The search of this area failed: {error}', { error: region.error ?? '' });
    case 'done': {
      if (region.foundCount === 0) {
        return t('memories', 'No new face was found in this area.');
      }
      const found = n('memories', '{count} new face found.', '{count} new faces found.', region.foundCount, {
        count: region.foundCount,
      });
      const excluded: string[] = [];
      if (region.tooSmallCount > 0) {
        excluded.push(
          n('memories', '{count} is too small', '{count} are too small', region.tooSmallCount, {
            count: region.tooSmallCount,
          }),
        );
      }
      if (region.lowConfidenceCount > 0) {
        excluded.push(
          n('memories', '{count} is not trusted enough', '{count} are not trusted enough', region.lowConfidenceCount, {
            count: region.lowConfidenceCount,
          }),
        );
      }
      if (excluded.length === 0) {
        return found;
      }
      return (
        found +
        ' ' +
        t('memories', 'Not used for the automatic recognition: {reasons}.', { reasons: excluded.join(', ') })
      );
    }
    default:
      return t('memories', 'Unknown state ({state}).', { state: region.state });
  }
}

export function stageRegionOf(region: IManualRegion, width: number, height: number, highlighted = false): StageRegion {
  return {
    id: region.id,
    rect: fractionsOf(region, width, height),
    classes: [`region-${region.state}`, ...(highlighted ? ['highlighted'] : [])],
    title: regionText(region),
  };
}

/**
 * What saving a marking does, for the name entered: nothing but this photo
 * without a name, a new person with a new one, and with one that exists the
 * future recognition of that person across the library.
 */
export function markingScope(name: string, knownNames: string[]): string {
  if (!name) {
    return t(
      'memories',
      'Without a name the marking applies to this photo only, until the automatic recognition assigns it to a person. If a face is found in the marked area, it is used for the automatic recognition.',
    );
  }
  if (knownNames.includes(name)) {
    return t(
      'memories',
      '"{name}" already exists: the marking is added to this person. If a face is found in the marked area, it also affects which faces are recognized as "{name}" in your whole library.',
      { name },
    );
  }
  return t(
    'memories',
    'A new person "{name}" is created for this photo. If a face is found in the marked area, it is used to recognize "{name}" automatically.',
    { name },
  );
}

export function regionScope(): string {
  return t(
    'memories',
    'The area is searched for faces on the next run of the face recognition. Every face found in it is added to this photo without a name, and left to the automatic recognition.',
  );
}

/**
 * What assigning an existing face to a person does: that face alone, which
 * the recognition does not learn from, or its whole group.
 */
export function reassignScope(name: string, wholeGroup: boolean, groupSize: number | null): string {
  if (wholeGroup && groupSize !== null) {
    return n(
      'memories',
      'The whole group, {count} face, is assigned to "{name}", and so are the faces that join it later. Other groups of the same person stay as they are.',
      'The whole group, {count} faces, is assigned to "{name}", and so are the faces that join it later. Other groups of the same person stay as they are.',
      groupSize,
      { count: groupSize, name },
    );
  }
  return t(
    'memories',
    'Only this face is assigned to "{name}". The other faces of its current person stay as they are, and this correction does not change the automatic recognition.',
    { name },
  );
}

/** How often, and how far apart, a field that is still loading is looked for */
const FOCUS_TRIES = 20;
const FOCUS_RETRY_MS = 50;

/**
 * Puts the cursor in a field without scrolling to it: the fields appear
 * below the photo while the user is still looking at it. Only with a mouse;
 * on a touch screen the keyboard would cover the photo. NcTextField is
 * loaded on first use, so the field is looked for a few times.
 */
export function focusWithoutScrolling(find: () => HTMLInputElement | null | undefined): void {
  if (!window.matchMedia?.('(pointer: fine)').matches) return;
  let tries = FOCUS_TRIES;
  const attempt = () => {
    const input = find();
    if (input) {
      input.focus({ preventScroll: true });
    } else if (--tries > 0) {
      window.setTimeout(attempt, FOCUS_RETRY_MS);
    }
  };
  attempt();
}

/** The input of an NcTextField given as a ref, once it has loaded. */
export function inputOf(field: unknown): HTMLInputElement | null {
  const el = (field as { $el?: Element } | undefined)?.$el;
  return el?.querySelector?.('input') ?? null;
}

/** The answer of the server that came with a failed request, if there was one. */
function responseOf(e: unknown): { status?: number; data?: { error?: unknown } } | null {
  if (typeof e !== 'object' || e === null || !('response' in e)) return null;
  const response = (e as { response?: unknown }).response;
  return typeof response === 'object' && response !== null ? response : null;
}

/** What went wrong with a call to the server, in words. */
export function errorText(e: unknown, fallback: string): string {
  const response = responseOf(e);
  if (response?.status === 503) {
    return t('memories', 'Face Recognition on the server has to be updated first.');
  }
  if (response?.status === 409) {
    return t('memories', 'The face has moved to another group in the meantime. Please check it and try again.');
  }
  const detail = response?.data?.error;
  return detail ? `${fallback} (${String(detail)})` : fallback;
}
