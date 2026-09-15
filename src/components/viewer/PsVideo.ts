import { showError } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import staticConfig from '@services/static-config';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { API } from '@services/API';

import type PhotoSwipe from 'photoswipe';
import type { PsContent, PsEvent, PsSlide } from './types';
import type { MediaPlayerElement } from 'vidstack/elements';
import type { MediaErrorEvent, MediaProviderChangeEvent, PlayerSrc } from 'vidstack';
import type Hls from 'hls.js';

type VideoContent = PsContent & {
  videoPlayer: MediaPlayerElement | null;
  /** Player creation in progress (sync guard for async chunk load) */
  videoStarting: boolean;
  videoIsHls: boolean;
  /** Source fallback already attempted */
  videoFailedOver: boolean;
  /** Playback started at least once (don't fail over mid-playback) */
  videoHasPlayed: boolean;
};

type PsVideoEvent = PsEvent & {
  content: VideoContent;
};

/** Interactive player elements; drags starting here must not move the slide */
const PLAYER_UI_SELECTOR = [
  'media-controls',
  'media-menu',
  'media-play-button',
  'media-mute-button',
  'media-fullscreen-button',
  'media-pip-button',
  'media-caption-button',
  'media-seek-button',
  'media-airplay-button',
  'media-live-button',
  'media-menu-button',
  'media-menu-item',
  'media-menu-items',
  'media-time-slider',
  'media-volume-slider',
  'media-slider-video',
  'media-thumbnail',
  'button',
  'input',
  '[role="slider"]',
  '[role="menu"]',
  '[role="menuitem"]',
].join(', ');

// Cap buffer to avoid overloading go-vod while
// processing requests from multiple users.
const HLS_LIVE_CONFIG = {
  /** Forward buffer target in seconds. */
  maxBufferLength: 30,
  /** Hard cap for forward buffer growth. */
  maxMaxBufferLength: 30,
  /** Backward buffer kept for seeking back. */
  backBufferLength: 30,
  /** Fetch the first segment while the manifest is still parsing. */
  startFragPrefetch: true,
  /** Tolerate segments not opening on a keyframe (split_by_time). */
  maxBufferHole: 0.5,
};

/**
 * Check if slide has video content
 */
export function isVideoContent(content: unknown): content is VideoContent {
  return typeof content === 'object' && (<VideoContent>content)?.data?.type === 'video';
}

class VideoContentSetup {
  wakeLock: WakeLockSentinel | null = null;

  /** Vidstack chunk, prefetched so controls mount instantly on activation */
  private vidstack = import('@services/vidstack');

  constructor(lightbox: PhotoSwipe) {
    this.initLightboxEvents(lightbox);
    lightbox.on('init', () => {
      this.initPswpEvents(lightbox);
    });
  }

  initLightboxEvents(lightbox: PhotoSwipe) {
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

  initPswpEvents(pswp: PhotoSwipe) {
    // Drags starting on player UI (controls, sliders, menus) belong to the
    // player; keep PhotoSwipe from dragging the slide underneath.
    pswp.on('pointerDown', (e) => {
      if (!isVideoContent(pswp.currSlide)) return;
      const target = e.originalEvent?.target as HTMLElement | null;
      if (target?.closest?.(PLAYER_UI_SELECTOR)) {
        e.preventDefault();
      }
    });

    pswp.on('close', () => {
      this.destroyPlayer(pswp.currSlide?.content as VideoContent);
    });

    // Reveal once the opening animation lands; until then the placeholder
    // carries the transition. Vue applies fully-opened on next tick, so defer.
    pswp.on('openingAnimationEnd', () => {
      window.setTimeout(() => this.maybeRevealPlayer(pswp.currSlide?.content as VideoContent), 50);
    });

    // Prevent closing when video fullscreen is active
    pswp.on('pointerMove', (e) => {
      if (document.fullscreenElement?.tagName === 'MEDIA-PLAYER') {
        e.preventDefault();
      }
    });
  }

  getDirectSrc(content: VideoContent): PlayerSrc {
    return {
      src: content.data.src,
      type: 'video/mp4', // chrome refuses to play video/quicktime, so fool it
    };
  }

  getHLSsrc(content: VideoContent): PlayerSrc {
    const fileid = content.data.photo.fileid;
    return {
      src: API.VIDEO_TRANSCODE(fileid),
      type: 'application/x-mpegurl',
    };
  }

  getLocalSrc(content: VideoContent): PlayerSrc | null {
    const url = nativex.getLocalVideoUrl(content.data.photo);
    if (!url) return null;
    return {
      src: url,
      type: 'video/mp4',
    };
  }

  /** Initial source: HLS unless transcoding is disabled */
  getPreferredSrc(content: VideoContent): { src: PlayerSrc; videoIsHls: boolean } {
    const local = this.getLocalSrc(content);
    if (local) {
      return { src: local, videoIsHls: false };
    } else if (!staticConfig.getSync('vod_disable')) {
      return { src: this.getHLSsrc(content), videoIsHls: true };
    } else {
      return { src: this.getDirectSrc(content), videoIsHls: false };
    }
  }

  async initPlayer(content: VideoContent) {
    if (!isVideoContent(content) || content.videoPlayer || content.videoStarting) {
      return;
    }
    content.videoStarting = true;

    try {
      await this.initPlayerInner(content);
    } finally {
      content.videoStarting = false;
    }
  }

  async initPlayerInner(content: VideoContent) {
    // Prevent screen from sleeping
    this.getWakeLock();

    const { isHLSProvider, Hls } = await this.vidstack;

    // Slide may have been destroyed or deactivated while loading the player chunk
    if (!isVideoContent(content) || content.videoPlayer || !content.element || !content.slide?.isActive) {
      return;
    }

    // Late mount: controls render now, media loads since the slide is active.
    // Starts hidden, revealed once fully opened; poster thumbs pre-playback.
    const { src, videoIsHls } = this.getPreferredSrc(content);

    // Make elements.
    const player = document.createElement('media-player') as MediaPlayerElement;
    player.style.opacity = '0';
    player.src = src;
    player.poster = content.data.msrc ?? '';
    player.title = content.data.photo.basename ?? '';
    player.playsInline = true;
    player.preload = 'metadata';
    player.autoPlay = true;
    if (staticConfig.getSync('video_loop')) {
      player.loop = true;
    }

    // Let the player lock orientation in fullscreen (replaces manual handling)
    const { w, h } = content.data.photo;
    if (w && h) {
      player.fullscreenOrientation = h < w ? 'landscape' : 'portrait';
    }

    // Visibility is owned by Photoswipe (CSS sync)
    player.controls.canIdle = false;

    const providerEl = document.createElement('media-provider');
    const posterEl = document.createElement('media-poster');
    posterEl.classList.add('vds-poster');

    const layout = document.createElement('media-video-layout');
    layout.setAttribute('small-when', 'never');

    player.addEventListener('provider-change', (e: Event) => {
      const provider = (e as MediaProviderChangeEvent).detail;
      if (isHLSProvider(provider)) {
        provider.library = Hls;
        provider.config = {
          ...provider.config,
          ...HLS_LIVE_CONFIG,
        };
      }

      // Prevent showing any default poster like a big play button.
      providerEl.querySelector('video')?.setAttribute('poster', utils.constants.BLANK_IMG);
    });

    player.addEventListener('hls-instance', (e: Event) => {
      const hls = (e as CustomEvent).detail as Hls;
      Object.assign(hls.config, HLS_LIVE_CONFIG);
      this.pickInitialLevel(hls);
    });

    player.addEventListener('playing', () => {
      if (!isVideoContent(content) || content.videoPlayer !== player) return;
      content.videoHasPlayed = true;
    });

    player.addEventListener('error', (e: Event) => {
      this.onPlayerError(content, e as MediaErrorEvent);
    });

    // Append elements.
    providerEl.appendChild(posterEl);
    player.appendChild(providerEl);
    player.appendChild(layout);
    content.videoPlayer = player;
    content.videoIsHls = videoIsHls;
    content.element.appendChild(player);

    // Full-viewport player in the slide holder; onZoomPanUpdate mirrors
    // PhotoSwipe's native slide values onto the picture layer.
    content.slide?.holderElement?.appendChild(content.element);

    // Reveal once fully opened (or shortly after, if the opening
    // animation events don't fire, e.g. animation disabled).
    this.maybeRevealPlayer(content);
    window.setTimeout(() => this.maybeRevealPlayer(content, true), 1500);
  }

  /** Show the player once the viewer is fully opened (poster covers pre-playback) */
  maybeRevealPlayer(content: VideoContent, force = false) {
    const player = content?.videoPlayer;
    if (!player || !isVideoContent(content)) return;
    if (force || document.querySelector('.memories-viewer.fully-opened')) {
      player.style.opacity = '1';
    }
  }

  /**
   * Fall back between HLS and direct on initial load failure.
   * Mid-playback errors are left to hls.js recovery.
   */
  onPlayerError(content: VideoContent, _e: MediaErrorEvent) {
    if (!isVideoContent(content) || content.videoFailedOver || content.videoHasPlayed) return;
    if (utils.isLocalPhoto(content.data.photo)) return; // local-only
    if (staticConfig.getSync('vod_disable')) return;

    const player = content.videoPlayer;
    if (!player) return;
    content.videoFailedOver = true;

    const wasHLS = content.videoIsHls;
    content.videoIsHls = !wasHLS;

    if (wasHLS) {
      console.warn('PsVideo: HLS stream failed, falling back to direct');
      if (utils.isAdmin) {
        showError(t('memories', 'Transcoding failed, check Nextcloud logs.'));
      }
    } else {
      console.warn('PsVideo: Direct video stream could not be opened, trying HLS');
    }

    try {
      player.src = wasHLS ? this.getDirectSrc(content) : this.getHLSsrc(content);
    } catch {
      // Ignore - video destroyed?
    }
  }

  destroyPlayer(content: VideoContent) {
    if (!isVideoContent(content)) return;

    this.releaseWakeLock();

    try {
      void content.videoPlayer?.pause()?.catch(() => undefined);
    } catch {
      // Ignore - player not ready
    }
    // Removal triggers vidstack's own teardown via disconnect.
    // Do NOT call player.destroy() here: it nulls shared player state
    // before descendants dispose, which throws inside layout disposal.
    content.videoPlayer?.remove();
    content.videoPlayer = null;
    content.videoFailedOver = false;
    content.videoHasPlayed = false;
  }

  onContentDestroy({ content }: PsVideoEvent) {
    this.destroyPlayer(content);
  }

  onContentResize(e: PsVideoEvent & { width: number; height: number }) {
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

  isContentZoomable(isZoomable: boolean, content: PsContent) {
    return isVideoContent(content) || isZoomable;
  }

  isKeepingPlaceholder(keep: boolean, content: PsContent) {
    return isVideoContent(content) || keep;
  }

  onContentActivate({ content }: PsVideoEvent) {
    this.initPlayer(content).catch((e) => console.error('PsVideo: failed to init player', e));
  }

  onContentDeactivate({ content }: PsVideoEvent) {
    this.destroyPlayer(content);
  }

  /**
   * Mirror the native slide transform onto the picture layer only.
   * Maps the target picture rect back to the viewport-aspect box that
   * contains it centered; identity at rest, so settled visuals are pure CSS.
   */
  onZoomPanUpdate({ slide }: { slide: PsSlide }) {
    const content = slide?.content as VideoContent | undefined;
    if (!content || !isVideoContent(content)) return;

    const el = content.element as HTMLElement | undefined;
    const provider = el?.querySelector('media-provider') as HTMLElement | null;
    if (!el || !provider) return;

    if (el.parentElement !== slide.holderElement) return;

    const vw0 = slide.panAreaSize.x;
    const vh0 = slide.panAreaSize.y;
    const dispW = slide.width * slide.zoomLevels.initial;
    const dispH = slide.height * slide.zoomLevels.initial;
    const k = slide.zoomLevels.initial ? slide.currZoomLevel / slide.zoomLevels.initial : NaN;
    if (!vw0 || !vh0 || !dispW || !dispH || !Number.isFinite(k)) {
      if (provider.style.transform) provider.style.transform = '';
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
    provider.style.transform = settled ? '' : `translate3d(${bx}px, ${by}px, 0) scale(${s})`;
  }

  onContentLoad(e: PsVideoEvent) {
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

  useContentPlaceholder(usePlaceholder: boolean, content: PsContent) {
    return isVideoContent(content) || usePlaceholder;
  }

  async getWakeLock() {
    try {
      await this.releaseWakeLock();
      this.wakeLock = await navigator.wakeLock?.request('screen');
    } catch (e) {
      console.warn('PsVideo: Failed to get wake lock', e);
    }
  }

  async releaseWakeLock() {
    try {
      await this.wakeLock?.release();
    } finally {
      this.wakeLock = null;
    }
  }

  /** Start at the admin default quality ('-1' = original). */
  pickInitialLevel(hls: Hls) {
    const spec = staticConfig.getSync('video_default_quality');
    if (!spec || spec === '0') return;

    const Events = (hls.constructor as typeof Hls).Events;
    hls.once(Events.MANIFEST_PARSED, () => {
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

      try {
        hls.nextLevel = idx;
      } catch {
        // Player may be gone by the time the manifest parses.
      }
    });
  }
}

export default VideoContentSetup;
