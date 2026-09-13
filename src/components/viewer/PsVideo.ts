import { showError } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import staticConfig from '@services/static-config';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { API } from '@services/API';

import type PhotoSwipe from 'photoswipe';
import type { PsContent, PsEvent } from './types';
import type { MediaPlayerElement } from 'vidstack/elements';
import type { MediaErrorEvent, MediaProviderChangeEvent, PlayerSrc } from 'vidstack';

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

  /** Initial source: HLS unless transcoding is disabled */
  getPreferredSrc(content: VideoContent): { src: PlayerSrc; videoIsHls: boolean } {
    if (!staticConfig.getSync('vod_disable')) {
      return { src: this.getHLSsrc(content), videoIsHls: true };
    }
    return { src: this.getDirectSrc(content), videoIsHls: false };
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

    // Hand off to native player if available
    if (nativex.has()) {
      // Local videos are played back directly
      // Remote videos are played back via HLS / Direct
      nativex.playVideo(content.data.photo, [API.VIDEO_TRANSCODE(content.data.photo.fileid), content.data.src]);
      return;
    }

    const { isHLSProvider, Hls } = await this.vidstack;

    // Slide may have been destroyed or deactivated while loading the player chunk
    if (!isVideoContent(content) || content.videoPlayer || !content.element || !content.slide?.isActive) {
      return;
    }

    // Late mount: controls render now, media loads since the slide is active.
    // Starts hidden, revealed once fully opened; poster thumbs pre-playback.
    const { src, videoIsHls } = this.getPreferredSrc(content);
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

    player.appendChild(document.createElement('media-provider'));
    player.appendChild(document.createElement('media-video-layout'));

    player.addEventListener('provider-change', (e: Event) => {
      const provider = (e as MediaProviderChangeEvent).detail;
      if (isHLSProvider(provider)) {
        provider.library = Hls;
      }
    });

    player.addEventListener('playing', () => {
      if (!isVideoContent(content) || content.videoPlayer !== player) return;
      content.videoHasPlayed = true;
      // Hide the preview image only once playback actually starts
      content.placeholder?.element?.setAttribute('hidden', 'true');
    });

    player.addEventListener('error', (e: Event) => {
      this.onPlayerError(content, e as MediaErrorEvent);
    });

    content.videoPlayer = player;
    content.videoIsHls = videoIsHls;
    content.element.appendChild(player);

    // Move the container to the slide holder for full-viewport controls
    // (like the old Plyr chrome); the video itself is letterboxed via CSS.
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

    if (nativex.has()) {
      // Add a timeout in case another video initializes
      // immediately after this one is destroyed
      setTimeout(() => nativex.destroyVideo(content.data.photo), 500);
      return;
    }

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

    content.placeholder?.element?.removeAttribute('hidden');
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
    return !isVideoContent(content) && isZoomable;
  }

  isKeepingPlaceholder(keep: boolean, content: PsContent) {
    if (isVideoContent(content)) {
      return true;
    }
    return keep;
  }

  onContentActivate({ content }: PsVideoEvent) {
    this.initPlayer(content).catch((e) => console.error('PsVideo: failed to init player', e));
  }

  onContentDeactivate({ content }: PsVideoEvent) {
    this.destroyPlayer(content);
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
}

export default VideoContentSetup;
