import { showError } from '@services/utils/dialog';

import { translate as t } from '@services/l10n';
import { config } from '@services/user-config';
import { getPlayableVideoCodecsSync } from '@services/video/codec';
import { attachVideoQualityHlsJs, detachVideoQuality } from './PsVideoQuality';
import * as utils from '@services/utils/common';
import * as nativex from '@native';
import { API } from '@services/API';

import type { ViewerControlsCE, ViewerVideoNativeHoleCE } from '@components/custom-elements';
import type { VideoController, VideoControllerElement } from '@typings';
import type { PsContent, PsEvent, PsPhotoSwipe, PsSlide } from './types';
import type Hls from 'hls.js';
import type { FragmentLoaderConstructor, FragmentLoaderContext, HlsConfig } from 'hls.js';

type VideoSource = {
  src: string;
  type: string;
};

/** Native hole custom element plus the VideoController methods it exposes. */
type NativeHolePlayer = ViewerVideoNativeHoleCE & Pick<VideoController, 'play' | 'pause' | 'load'>;

type VideoContent = PsContent & {
  videoPlayer: VideoControllerElement | null;
  videoHls: Hls | null;
  videoIsHls: boolean;
  /** Source fallback already attempted */
  videoFailedOver: boolean;
  /** Playback started at least once (don't fail over mid-playback) */
  videoHasPlayed: boolean;
  /** Abort for in-flight setup; renewed on activate, aborted on destroy. */
  videoAbort: AbortController | null;
};

type PsVideoEvent = PsEvent & {
  content: VideoContent;
};

/** Interactive player elements; drags starting here must not move the slide */
const PLAYER_UI_SELECTOR = [
  'viewer-controls',
  '.m-vc-controls',
  '.m-vc-center',
  '.v-popper__popper',
  'button',
  'input',
  '[role="slider"]',
  '[role="menu"]',
  '[role="menuitem"]',
].join(', ');

// Cap buffer to avoid overloading go-vod while
// processing requests from multiple users.
function getHlsConfig(HlsClass: typeof Hls): Partial<HlsConfig> {
  return {
    /** Forward buffer target in seconds. */
    maxBufferLength: 30,
    /** Hard cap for forward buffer growth. */
    maxMaxBufferLength: 30,
    /** Backward buffer kept for seeking back. */
    backBufferLength: 30,
    /** Fetch the first segment while the manifest is still parsing. */
    startFragPrefetch: true,
    /** direct.m3u8 409s while probing; fail over fast, retry upgrade. */
    playlistLoadPolicy: {
      default: {
        maxTimeToFirstByteMs: 3000,
        maxLoadTimeMs: 3000,
        timeoutRetry: { maxNumRetry: 100, retryDelayMs: 0, maxRetryDelayMs: 0 },
        errorRetry: { maxNumRetry: 100, retryDelayMs: 1000, maxRetryDelayMs: 8000 },
      },
    },
    errorPenaltyExpireMs: 3000,
    /** Smooth estimate: cold transcode latency is not low bandwidth. */
    abrEwmaFastVoD: 10,
    abrEwmaSlowVoD: 15,
    abrEwmaDefaultEstimate: 8000000,
    abrMaxWithRealBitrate: true,
    /** Down fast, up only on sustained headroom. */
    abrBandWidthFactor: 0.8,
    abrBandWidthUpFactor: 0.5,
    /** Keep abandon responsive; slow transcode must downswitch, not stall. */
    maxStarvationDelay: 4,
    maxLoadingDelay: 4,
    /**
     * Cancel init segment loads silently. hls.js aborts an in-flight init on every
     * fragment retry, and that init's abort handler then aborts the retry's
     * fragment, so an init slower than one retry tick (cold transcode) loops forever.
     */
    fLoader: class extends HlsClass.DefaultConfig.loader {
      abort() {
        if ((this.context as FragmentLoaderContext | null)?.frag?.sn === 'initSegment') {
          this.destroy();
        } else {
          super.abort();
        }
      }
    } as FragmentLoaderConstructor,
  };
}

/**
 * Check if slide has video content
 */
export function isVideoContent(content: unknown): content is VideoContent {
  return typeof content === 'object' && (<VideoContent>content)?.data?.type === 'video';
}

class VideoContentSetup {
  wakeLock: WakeLockSentinel | null = null;

  /** hls.js chunk, prefetched so the player mounts fast on activation */
  private hls = import('hls.js');

  constructor(private lightbox: PsPhotoSwipe) {
    this.initLightboxEvents(lightbox);
    lightbox.on('init', () => {
      this.initPswpEvents(lightbox);
    });
  }

  private initLightboxEvents(lightbox: PsPhotoSwipe) {
    lightbox.on('contentLoad', (e) => this.onContentLoad(e as unknown as PsVideoEvent));
    lightbox.on('contentDestroy', (e) => this.onContentDestroy(e as unknown as PsVideoEvent));
    lightbox.on('contentActivate', (e) => this.onContentActivate(e as unknown as PsVideoEvent));
    lightbox.on('contentDeactivate', (e) => this.onContentDeactivate(e as unknown as PsVideoEvent));
    lightbox.on('contentResize', (e) => this.onContentResize(e as unknown as typeof e & PsVideoEvent));
    lightbox.on('zoomPanUpdate', (e) => this.onZoomPanUpdate(e as unknown as { slide: PsSlide }));

    lightbox.addFilter('isKeepingPlaceholder', (k, c) => this.isKeepingPlaceholder(k, c as unknown as PsContent));
    lightbox.addFilter('isContentZoomable', (z, c) => this.isContentZoomable(z, c as unknown as PsContent));
    lightbox.addFilter('useContentPlaceholder', (u, c) => this.useContentPlaceholder(u, c as unknown as PsContent));
  }

  private initPswpEvents(pswp: PsPhotoSwipe) {
    // Drags starting on player UI (controls, sliders, menus) belong to the
    // player; keep PhotoSwipe from dragging the slide underneath.
    pswp.on('pointerDown', (e) => {
      if (!this.getCurrVideoContent(pswp)) return;
      const target = e.originalEvent?.target as HTMLElement | null;
      if (target?.closest?.(PLAYER_UI_SELECTOR)) {
        e.preventDefault();
      }
    });

    pswp.on('close', () => {
      const content = this.getCurrVideoContent(pswp);
      if (content) this.destroyPlayer(content);
    });

    // Prevent closing when video fullscreen is active
    pswp.on('pointerMove', (e) => {
      if (!this.getCurrVideoContent(pswp)) return;
      const fs = document.fullscreenElement as HTMLElement | null;
      if (fs && (fs.classList.contains('video-container') || fs.querySelector('video'))) {
        e.preventDefault();
      }
    });

    // Space toggles playback unless a focused control handles it
    pswp.on('keydown', (e) => {
      const player = this.getCurrVideoContent(pswp)?.videoPlayer;
      if (!player) return;

      const { key, target, repeat } = e.originalEvent;
      if (e.defaultPrevented || key !== ' ' || repeat) return;
      if (target !== document.body && target !== pswp.element) return;

      e.originalEvent.preventDefault();
      if (player.paused) {
        utils.runCatching(() => player.play());
      } else {
        player.pause();
      }
    });
  }

  private getCurrVideoContent(pswp: PsPhotoSwipe): VideoContent | null {
    const content = pswp.currSlide?.content;
    return isVideoContent(content) ? content : null;
  }

  private getDirectSrc(content: VideoContent): VideoSource {
    return {
      src: content.data.src,
      type: 'video/mp4', // chrome refuses to play video/quicktime, so fool it
    };
  }

  private getHLSsrc(content: VideoContent, player: HTMLElement): VideoSource {
    const fileid = content.data.photo.fileid;
    return {
      src: API.VIDEO_TRANSCODE(fileid, 'index.m3u8', this.getPlayableCodecs(player)),
      type: 'application/x-mpegurl',
    };
  }

  private getLocalSrc(content: VideoContent, player: HTMLElement): VideoSource | null {
    const url = nativex.getLocalVideoUrl(content.data.photo, this.isNativeHolePlayer(player));
    if (!url) return null;
    return {
      src: url,
      type: 'video/mp4',
    };
  }

  /** Initial source: HLS unless transcoding is disabled */
  private getPreferredSrc(content: VideoContent, player: HTMLElement): { src: VideoSource; videoIsHls: boolean } {
    // Trash has no transcoding, play the file directly.
    if (content.data.photo.trashInfo) {
      return { src: this.getDirectSrc(content), videoIsHls: false };
    }

    // Check if a local file is available (NativeX).
    const local = this.getLocalSrc(content, player);
    if (local) {
      return { src: local, videoIsHls: false };
    } else if (!config.vod_disable) {
      return { src: this.getHLSsrc(content, player), videoIsHls: true };
    } else {
      return { src: this.getDirectSrc(content), videoIsHls: false };
    }
  }

  private getPosterSrc(content: PsContent): string {
    return utils.getPreviewUrl({
      photo: content.data.photo,
      msize: 1024,
    });
  }

  private getStoryboardUrl(content: VideoContent, player: HTMLElement): string | null {
    if (utils.isLocalPhoto(content.data.photo)) return null;
    const api = API.VIDEO_STORYBOARD(content.data.photo.fileid, 'storyboard.vtt', this.getPlayableCodecs(player));
    return utils.runCatching(() => new URL(api, window.location.href).href);
  }

  private async initPlayer(content: VideoContent) {
    // Slide may have been destroyed while waiting for activation
    if (!isVideoContent(content) || content.videoPlayer || !content.element || !content.slide?.isActive) {
      return;
    }

    // Clean up in case old state is remaining.
    utils.runCatching(() => content.videoHls?.destroy());
    content.videoHls = null;

    // Fresh abort for this setup; destroy aborts it.
    content.videoAbort?.abort();
    content.videoAbort = new AbortController();
    const signal = content.videoAbort.signal;

    // Make elements.
    const stage = document.createElement('div');
    stage.className = 'memories-video-stage';

    // Backing player: native hole view when available, else the web video tag.
    const player: VideoControllerElement = nativex.viewVideoIsEnabled()
      ? this.initPlayerNativeHole(content)
      : this.initPlayerWeb(content);
    if (!player || signal.aborted) return;

    player.addEventListener(
      'playing',
      () => {
        if (signal.aborted) return;
        content.videoHasPlayed = true;
        if (content.slide) {
          content.slide.data.nxVideoState = 'loaded';
        }
        player.dataset.started = 'true';
        this.lightbox.dispatch('initialZoomPan');
        this.getWakeLock();
      },
      { signal },
    );
    player.addEventListener(
      'error',
      () => {
        if (content.slide) {
          content.slide.data.nxVideoState = 'error';
        }
        void this.tryFailover(content);
      },
      { signal },
    );

    if (config.video_autoplay === 'true') {
      player.preload = 'metadata';
      player.autoplay = true;
    } else {
      player.preload = 'none';
      player.autoplay = false;
    }

    if (config.video_loop) {
      player.loop = true;
    }

    await this.attachPlayerSource(content, player, signal);
    if (signal.aborted) return;
    content.videoPlayer = player;

    stage.appendChild(player);
    content.element.appendChild(stage);

    // Full-viewport player in the slide holder; onZoomPanUpdate mirrors
    // PhotoSwipe's native slide values onto the picture layer.
    content.slide?.holderElement?.appendChild(content.element);

    // Native controls (Vue custom element, registered globally).
    const viewerControls = document.createElement('viewer-controls') as ViewerControlsCE;
    viewerControls.video = player;
    viewerControls.thumbnails = this.getStoryboardUrl(content, player);
    content.element.appendChild(viewerControls);

    // Attempt autoplay (may be blocked until user gesture).
    if (config.video_autoplay === 'true') {
      utils.runCatching(() => player.play());
    }
  }

  /** Backing web player: video tag. */
  private initPlayerWeb(content: VideoContent): VideoControllerElement {
    const player = document.createElement('video');
    player.className = 'memories-video';
    player.poster = this.getPosterSrc(content);
    player.playsInline = true;
    player.controls = false;
    player.volume = config.video_volume;
    player.muted = config.video_muted;
    player.defaultPlaybackRate = config.video_speed;
    player.playbackRate = config.video_speed;
    return player;
  }

  /** Backing fake element for native player. */
  private initPlayerNativeHole(content: VideoContent): VideoControllerElement {
    const element = document.createElement('viewer-video-native-hole') as ViewerVideoNativeHoleCE;
    element.photo = content.data.photo;
    return element as any;
  }

  /** Attach the preferred source: element src for the native hole, HLS/direct for web. */
  private async attachPlayerSource(content: VideoContent, player: VideoControllerElement, signal: AbortSignal) {
    // Late mount: media loads since the slide is active.
    const { src, videoIsHls } = this.getPreferredSrc(content, player);
    content.videoIsHls = videoIsHls;

    if (player instanceof HTMLVideoElement) {
      if (videoIsHls) {
        await this.attachHlsSource(content, player, src.src, signal);
      } else {
        this.attachDirectSource(player, src);
      }
    } else if (this.isNativeHolePlayer(player)) {
      player.src = nativex.addOrigin(src.src);
    }
  }

  private isNativeHolePlayer(player: HTMLElement): player is NativeHolePlayer {
    return player.tagName === 'VIEWER-VIDEO-NATIVE-HOLE';
  }

  private getPlayableCodecs(player: HTMLElement): string[] {
    return this.isNativeHolePlayer(player)
      ? nativex.getNativePlayableCodecsSync()
      : (getPlayableVideoCodecsSync() ?? []);
  }

  private attachDirectSource(player: HTMLVideoElement, src: VideoSource) {
    // <source type> preserves the mp4 masquerade for quicktime.
    player.removeAttribute('src');
    player.innerHTML = '';
    const source = document.createElement('source');
    source.src = src.src;
    source.type = src.type;
    source.addEventListener('error', () => player.dispatchEvent(new Event('error')));
    player.appendChild(source);
    if (config.video_autoplay === 'true') {
      player.load();
    }
  }

  private async attachHlsSource(content: VideoContent, player: HTMLVideoElement, url: string, signal: AbortSignal) {
    // Clear any previous source (e.g. direct failover) so it can't win.
    player.removeAttribute('src');
    player.innerHTML = '';

    // Get the hls.js module (async chunk load).
    const mod = await this.hls;

    // Slide may have been destroyed while loading hls.js
    if (signal.aborted) {
      return;
    }

    const Hls = mod.default;
    if (!Hls.isSupported()) {
      // Fall back to native HLS if available (e.g. iOS <17.1)
      if (player.canPlayType('application/vnd.apple.mpegurl')) {
        player.src = url;
        if (config.video_autoplay === 'true') {
          player.load();
        }
        return;
      }

      console.warn('PsVideo: HLS not supported, falling back to direct');
      content.videoIsHls = false;
      this.attachDirectSource(player, this.getDirectSrc(content));
      return;
    }

    const hls = new Hls(getHlsConfig(Hls));
    content.videoHls = hls;
    hls.attachMedia(player);
    if (config.video_autoplay === 'true') {
      hls.loadSource(url);
    } else {
      player.addEventListener('play', () => hls.loadSource(url), { once: true, signal });
    }
    attachVideoQualityHlsJs(player, hls);
    this.pickInitialLevel(hls);

    hls.on(Hls.Events.ERROR, (_event, data) => {
      if (data?.fatal) {
        player.dispatchEvent(new Event('error'));
      }
    });
  }

  /**
   * Fall back between HLS and direct on initial load failure.
   * Mid-playback errors are left to hls.js recovery.
   */
  private async tryFailover(content: VideoContent) {
    if (!isVideoContent(content) || content.videoFailedOver || content.videoHasPlayed) return;
    if (utils.isLocalPhoto(content.data.photo)) return; // local-only
    if (content.data.photo.trashInfo) return; // trash
    if (config.vod_disable) return;

    const player = content.videoPlayer;
    const signal = content.videoAbort?.signal;
    if (!signal || !player) return;
    content.videoFailedOver = true;
    if (player instanceof HTMLVideoElement) {
      await this.tryFailoverWeb(content, player, signal);
    } else if (this.isNativeHolePlayer(player)) {
      this.tryFailoverNativeHole(content, player);
    }
  }

  private async tryFailoverWeb(content: VideoContent, player: HTMLVideoElement, signal: AbortSignal) {
    const wasHLS = content.videoIsHls;
    content.videoIsHls = !wasHLS;

    // Tear down HLS before switching sources.
    detachVideoQuality(player);
    utils.runCatching(() => content.videoHls?.destroy());
    content.videoHls = null;

    if (wasHLS) {
      console.warn('PsVideo: HLS stream failed, falling back to direct');
      if (utils.isAdmin) {
        showError(t('memories', 'Transcoding failed, check Nextcloud logs.'));
      }
      this.attachDirectSource(player, this.getDirectSrc(content));
    } else {
      console.warn('PsVideo: Direct video stream could not be opened, trying HLS');
      await this.attachHlsSource(content, player, this.getHLSsrc(content, player).src, signal);
    }

    if (signal.aborted) return;
    if (wasHLS) {
      utils.runCatching(() => player.load());
    }
    utils.runCatching(() => player.play());
  }

  private tryFailoverNativeHole(content: VideoContent, player: NativeHolePlayer) {
    const wasHLS = content.videoIsHls;
    content.videoIsHls = !wasHLS;

    const src = wasHLS ? this.getDirectSrc(content).src : this.getHLSsrc(content, player).src;
    if (wasHLS) {
      console.warn('PsVideo: Native hole HLS stream failed, falling back to direct');
    } else {
      console.warn('PsVideo: Native hole direct stream could not be opened, trying HLS');
    }
    player.src = nativex.addOrigin(src);
    utils.runCatching(() => player.play());
  }

  private destroyPlayer(content: VideoContent) {
    if (!isVideoContent(content)) return;
    content.videoAbort?.abort();

    this.releaseWakeLock();

    const player = content.videoPlayer;
    utils.runCatching(() => player?.pause());

    detachVideoQuality(player);
    utils.runCatching(() => content.videoHls?.destroy());
    content.videoHls = null;

    if (player) {
      utils.runCatching(() => {
        player.removeAttribute('src');
        player.load();
      });
    }
    if (content.element) {
      content.element.innerHTML = '';
    }
    if (content.slide) {
      content.slide.data.nxVideoState = undefined;
    }
    content.videoPlayer = null;
    content.videoFailedOver = false;
    content.videoHasPlayed = false;
  }

  private onContentDestroy({ content }: PsVideoEvent) {
    this.destroyPlayer(content);
  }

  private onContentResize(e: PsVideoEvent & { width: number; height: number }) {
    if (isVideoContent(e.content)) {
      e.preventDefault();

      const { width, height, content } = e;

      // Video size as CSS vars (used until fully-opened goes full viewport)
      content.element?.style.setProperty('--vw', `${width}px`);
      content.element?.style.setProperty('--vh', `${height}px`);

      // override placeholder size, so it more accurately matches the video
      const phStyle = content.placeholder?.element?.style;
      if (phStyle) {
        phStyle.transform = 'none';
        phStyle.width = `${width}px`;
        phStyle.height = `${height}px`;
      }
    }
  }

  private isContentZoomable(isZoomable: boolean, content: PsContent) {
    return isVideoContent(content) || isZoomable;
  }

  private isKeepingPlaceholder(keep: boolean, content: PsContent) {
    return isVideoContent(content) || keep;
  }

  private onContentActivate({ content }: PsVideoEvent) {
    this.initPlayer(content).catch((e) => console.error('PsVideo: failed to init player', e));
  }

  private onContentDeactivate({ content }: PsVideoEvent) {
    this.destroyPlayer(content);
  }

  /**
   * Mirror the native slide transform onto the picture layer only.
   * Maps the target picture rect back to the viewport-aspect box that
   * contains it centered; identity at rest, so settled visuals are pure CSS.
   */
  private onZoomPanUpdate({ slide }: { slide: PsSlide }) {
    const content = slide?.content as VideoContent | undefined;
    if (!content || !isVideoContent(content)) return;

    const el = content.element as HTMLElement | undefined;
    const stage = el?.querySelector('.memories-video-stage') as HTMLElement | null;
    if (!el || !stage) return;

    if (el.parentElement !== slide.holderElement) return;

    const vw0 = slide.panAreaSize.x;
    const vh0 = slide.panAreaSize.y;
    const dispW = slide.width * slide.zoomLevels.initial;
    const dispH = slide.height * slide.zoomLevels.initial;
    const k = slide.zoomLevels.initial ? slide.currZoomLevel / slide.zoomLevels.initial : NaN;
    if (!vw0 || !vh0 || !dispW || !dispH || !Number.isFinite(k)) {
      if (stage.style.transform) stage.style.transform = '';
      return;
    }

    const tw = dispW * k;
    const th = dispH * k;
    const tcx = slide.pan.x + tw / 2;
    const tcy = slide.pan.y + th / 2;

    const bw = Math.max(tw, (th * vw0) / vh0);
    const bh = Math.max(th, (tw * vh0) / vw0);
    const bx = tcx - bw / 2;
    const by = tcy - bh / 2;
    const s = bw / vw0;

    const settled = Math.abs(bx) < 0.5 && Math.abs(by) < 0.5 && Math.abs(s - 1) < 1e-6;
    stage.style.transform = settled ? '' : `translate3d(${bx}px, ${by}px, 0) scale(${s})`;
  }

  private onContentLoad(e: PsVideoEvent) {
    const content: PsContent = e.content;
    if (!isVideoContent(content)) return;

    // Stop default content load
    e.preventDefault();
    content.type = 'video';

    if (content.element) return;

    // Create DIV; the player is mounted on activation (late mount)
    content.element = document.createElement('div');
    content.element.classList.add('video-container');

    content.onLoaded();
  }

  private useContentPlaceholder(usePlaceholder: boolean, content: PsContent) {
    return isVideoContent(content) || usePlaceholder;
  }

  private async getWakeLock() {
    try {
      await this.releaseWakeLock();
      this.wakeLock = await navigator.wakeLock?.request('screen');
    } catch (e) {
      console.warn('PsVideo: Failed to get wake lock', e);
    }
  }

  private async releaseWakeLock() {
    try {
      await this.wakeLock?.release();
    } finally {
      this.wakeLock = null;
    }
  }

  /** Start at the admin default quality ('-1' = original). */
  private pickInitialLevel(hls: Hls) {
    const spec = config.video_default_quality;
    if (!spec || spec === '0') return;

    const applyLevel = () => {
      const levels = hls.levels;
      if (!levels?.length) return;

      let idx = levels.length - 1;
      if (spec !== '-1') {
        const target = Number.parseInt(spec, 10);
        if (!Number.isFinite(target) || target <= 0) return;
        const best =
          levels.filter((l) => (l.height ?? 0) <= target).sort((a, b) => (b.height ?? 0) - (a.height ?? 0))[0] ??
          levels[0];
        idx = levels.indexOf(best);
      }

      // Player may be gone by the time the manifest parses.
      utils.runCatching(() => (hls.nextLevel = idx));
    };

    if (hls.levels?.length) {
      applyLevel();
    } else {
      const { Events } = hls.constructor as typeof Hls;
      hls.once(Events.MANIFEST_PARSED, applyLevel);
    }
  }
}

export default VideoContentSetup;
