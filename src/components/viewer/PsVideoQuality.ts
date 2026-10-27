import type Hls from 'hls.js';
import type { QualityOption } from '@typings';

/** Internal quality hooks stored directly on the video element. */
interface QualityHooks extends HTMLVideoElement {
  _qualitySelected: string;
  _qualityHls?: Hls;
  _qualityHlsOnManifestParsed?: () => void;
  _qualityHlsOnLevelSwitched?: () => void;
}

function buildHlsQualityList(hls: Hls) {
  const byHeight = new Map<number, { index: number; bitrate: number }[]>();

  hls.levels.forEach((level, index) => {
    if (!level.height) return;
    const list = byHeight.get(level.height) ?? [];
    list.push({ index, bitrate: level.bitrate });
    byHeight.set(level.height, list);
  });

  return [...byHeight.entries()]
    .sort((a, b) => b[0] - a[0])
    .flatMap(([height, levels]) =>
      [...levels]
        .sort((a, b) => b.bitrate - a.bitrate)
        .map((level) => ({
          id: `${height}:${level.bitrate}`,
          size: height,
          bitrate: level.bitrate,
          index: level.index,
        })),
    );
}

function syncHlsQualitySelection(video: HTMLVideoElement) {
  const hooks = video as QualityHooks;
  const hls = hooks._qualityHls;
  if (!hls) return;

  let selected = 'auto';
  if (!hls.autoLevelEnabled) {
    const list = buildHlsQualityList(hls);
    const selOpt = list.find((o) => o.index === hls.currentLevel);
    selected = selOpt?.id ?? 'auto';
  }

  if (selected !== hooks._qualitySelected) {
    hooks._qualitySelected = selected;
    video.dispatchEvent(new CustomEvent('quality-selected'));
  }
}

function selectHlsQuality(video: HTMLVideoElement, id: string) {
  const hooks = video as QualityHooks;
  const hls = hooks._qualityHls;
  if (!hls) return;

  if (id === 'auto') {
    if (!hls.autoLevelEnabled) {
      hls.currentLevel = -1;
    }
    return;
  }

  const option = buildHlsQualityList(hls).find((o) => o.id === id);
  if (option && hls.currentLevel !== option.index) {
    hls.currentLevel = option.index;
  }
}

export function attachVideoQualityHlsJs(video: HTMLVideoElement | null, hls: Hls) {
  if (!video) {
    console.error('PsVideoQuality: video element not found for quality setup');
    return;
  }

  detachVideoQuality(video);

  const hooks = video as QualityHooks;
  hooks._qualityHls = hls;
  hooks._qualitySelected = 'auto';
  hooks._qualityHlsOnManifestParsed = () => {
    syncHlsQualitySelection(video);
    video.dispatchEvent(new CustomEvent('quality-list'));
  };
  hooks._qualityHlsOnLevelSwitched = () => {
    syncHlsQualitySelection(video);
  };

  const { Events } = hls.constructor as typeof Hls;
  hls.on(Events.MANIFEST_PARSED, hooks._qualityHlsOnManifestParsed);
  hls.on(Events.LEVEL_SWITCHED, hooks._qualityHlsOnLevelSwitched);

  Object.defineProperty(video, 'currentQuality', {
    configurable: true,
    get: () => hooks._qualitySelected ?? 'auto',
    set: (id: string) => selectHlsQuality(video, id),
  });

  Object.defineProperty(video, 'qualityList', {
    configurable: true,
    get: (): QualityOption[] => {
      if (!hooks._qualityHls) return [];
      return [{ id: 'auto', size: -1 }, ...buildHlsQualityList(hooks._qualityHls)];
    },
  });

  syncHlsQualitySelection(video);
  video.dispatchEvent(new CustomEvent('quality-list'));
}

export function detachVideoQuality(video: HTMLVideoElement | null | undefined) {
  if (!video) return;
  const hooks = video as QualityHooks;

  if (hooks._qualityHls) {
    try {
      const { Events } = hooks._qualityHls.constructor as typeof Hls;
      if (hooks._qualityHlsOnManifestParsed) {
        hooks._qualityHls.off(Events.MANIFEST_PARSED, hooks._qualityHlsOnManifestParsed);
      }
      if (hooks._qualityHlsOnLevelSwitched) {
        hooks._qualityHls.off(Events.LEVEL_SWITCHED, hooks._qualityHlsOnLevelSwitched);
      }
    } catch {
      // hls instance may already be destroyed
    }
  }

  delete hooks._qualityHls;
  delete hooks._qualityHlsOnManifestParsed;
  delete hooks._qualityHlsOnLevelSwitched;
  const fields = video as unknown as Record<string, unknown>;
  delete fields._qualitySelected;
  delete fields.currentQuality;
  delete fields.qualityList;
  video.dispatchEvent(new CustomEvent('quality-list'));
}
