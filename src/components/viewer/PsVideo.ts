import { showError } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import staticConfig from '@services/static-config';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { API } from '@services/API';

import type PhotoSwipe from 'photoswipe';
import type { PsContent, PsEvent } from './types';

import type _Player from 'video.js/dist/types/player';
import type _qualityLevels from 'videojs-contrib-quality-levels';

// The return type of the qualityLevels function is not right
type qualityLevels = (...args: Parameters<typeof _qualityLevels>) => ReturnType<typeof _qualityLevels> &
  {
    height: number | null;
    width: number | null;
    label: string | null;
    enabled: boolean;
  }[];

// Augment player with plugins
type Player = _Player & {
  qualityLevels?: qualityLevels;
};

type VideoContent = PsContent & {
  videoElement: HTMLVideoElement | null;
  videojs: Player | null;
  plyr: globalThis.Plyr | null;
};

type PsVideoEvent = PsEvent & {
  content: VideoContent;
};

/**
 * Check if slide has video content
 */
export function isVideoContent(content: unknown): content is VideoContent {
  return typeof content === 'object' && (<VideoContent>content)?.data?.type === 'video';
}

class VideoContentSetup {
  /** Last known quality that was set */
  lastQuality: number | null = null;

  /** Current wake lock */
  wakeLock: WakeLockSentinel | null = null;

  constructor(
    lightbox: PhotoSwipe,
    private options: {
      preventDragOffset: number;
    },
  ) {
    this.initLightboxEvents(lightbox);
    lightbox.on('init', () => {
      this.initPswpEvents(lightbox);
    });
  }

  initLightboxEvents(lightbox: PhotoSwipe) {
    lightbox.on('contentLoad', this.onContentLoad.bind(this));
    lightbox.on('contentDestroy', this.onContentDestroy.bind(this));
    lightbox.on('contentActivate', this.onContentActivate.bind(this));
    lightbox.on('contentDeactivate', this.onContentDeactivate.bind(this));
    lightbox.on('contentResize', this.onContentResize.bind(this));

    lightbox.addFilter('isKeepingPlaceholder', this.isKeepingPlaceholder.bind(this));
    lightbox.addFilter('isContentZoomable', this.isContentZoomable.bind(this));
    lightbox.addFilter('useContentPlaceholder', this.useContentPlaceholder.bind(this));
  }

  initPswpEvents(pswp: PhotoSwipe) {
    // Prevent draggin when pointer is in bottom part of the video
    // todo: add option for this
    pswp.on('pointerDown', (e) => {
      const slide = pswp.currSlide;
      if (isVideoContent(slide) && this.options.preventDragOffset) {
        const origEvent = e.originalEvent;
        if (origEvent.type === 'pointerdown') {
          // Check if directly over the videojs control bar
          const elems = document.elementsFromPoint(origEvent.clientX, origEvent.clientY);
          if (elems.some((el) => el.classList.contains('plyr__controls'))) {
            e.preventDefault();
            return;
          }

          const videoHeight = Math.ceil(slide.height * slide.currZoomLevel);
          const verticalEnding = videoHeight + slide.bounds.center.y;
          const pointerYPos = origEvent.pageY - pswp.offset.y;
          if (pointerYPos > verticalEnding - this.options.preventDragOffset && pointerYPos < verticalEnding) {
            e.preventDefault();
          }
        }
      }
    });

    // do not append video on nearby slides
    pswp.on('appendHeavy', (e) => {
      const content = e.slide.content;
      if (isVideoContent(content)) {
        if (e.slide.isActive && content.videoElement) {
          this.initVideo(content);
        }
      }
    });

    pswp.on('close', () => {
      this.destroyVideo(pswp.currSlide?.content as VideoContent);
    });

    // Prevent closing when video fullscreen is active
    pswp.on('pointerMove', (e) => {
      const plyr = (<VideoContent>pswp.currSlide?.content)?.plyr;
      if (plyr?.fullscreen.active) {
        e.preventDefault();
      }
    });
  }

  getDirectSrc(content: VideoContent) {
    return {
      src: content.data.src,
      type: 'video/mp4', // chrome refuses to play video/quicktime, so fool it
    };
  }

  getHLSsrc(content: VideoContent) {
    // Get base URL
    const fileid = content.data.photo.fileid;
    return {
      src: API.VIDEO_TRANSCODE(fileid),
      type: 'application/x-mpegURL',
    };
  }

  async initVideo(content: VideoContent) {
    if (!isVideoContent(content) || content.videojs) {
      return;
    }

    // Prevent screen from sleeping
    this.getWakeLock();

    // Sources list
    const sources: { src: string; type: string }[] = [];

    // Add HLS source if enabled
    if (!staticConfig.getSync('vod_disable')) {
      sources.push(this.getHLSsrc(content));
    }
    sources.push(this.getDirectSrc(content)); // direct source

    // Hand off to native player if available
    if (nativex.has()) {
      // Local videos are played back directly
      // Remote videos are played back via HLS / Direct
      nativex.playVideo(
        content.data.photo,
        sources.map((s) => s.src),
      );
      return;
    }

    // Prevent double loading
    content.videojs = {} as any;

    // Load videojs scripts
    if (!_m.video.videojs) {
      await import('@services/videojs');
    }

    // Create video element
    content.videoElement = document.createElement('video');
    content.videoElement.className = 'video-js';
    content.videoElement.setAttribute('poster', content.data.msrc!);
    content.videoElement.setAttribute('preload', 'none');
    content.videoElement.setAttribute('controls', '');
    content.videoElement.setAttribute('playsinline', '');

    // Add the video element to the actual container
    content.element?.appendChild(content.videoElement);

    const overrideNative = !_m.video.videojs.browser.IS_SAFARI;
    const vjs = (content.videojs = _m.video.videojs(content.videoElement, {
      fill: true,
      autoplay: true,
      controls: false,
      sources: sources,
      preload: 'metadata',
      playbackRates: [0.5, 1, 1.5, 2],
      responsive: true,
      retryOnError: true,
      html5: {
        vhs: {
          overrideNative: overrideNative,
          withCredentials: false,
          useBandwidthFromLocalStorage: true,
          useNetworkInformationApi: true,
          limitRenditionByPlayerDimensions: false,
          handlePartialData: true,
        },
        nativeAudioTracks: !overrideNative,
        nativeVideoTracks: !overrideNative,
      },
    }));

    // Play the video (hopefully)
    const playWithDelay = () => setTimeout(() => this.playNoThrow(content.videojs), 100);
    playWithDelay();

    // Initialize Plyr
    const initPlyr = () => {
      if (content.plyr) return;

      // Check if src is set to direct at the time of initialization.
      // If this is the case then loading the HLS stream failed
      // and we should warn the admin. We know this since the HLS src
      // is always the first, and the switch to user preferences is done
      // only during / after Plyr is initialized.
      // So any switches to direct till this point are failure fallbacks.
      if (!staticConfig.getSync('vod_disable')) {
        if (utils.isAdmin && overrideNative && !vjs.src(undefined)?.endsWith('.m3u8')) {
          showError(t('memories', 'Transcoding failed, check Nextcloud logs.'));
        }

        // Register error callback for fallback from direct to HLS.
        // Fallbacks till this point are handled by videojs natively.
        vjs.on('error', () => {
          if (!vjs.src(undefined)?.endsWith('.m3u8')) {
            console.warn('PsVideo: Direct video stream could not be opened, trying HLS');
            vjs.src(this.getHLSsrc(content));
          }
        });
      }

      this.initPlyr(content);
    };

    content.videojs.one('canplay', () => {
      content.videoElement = content.videojs?.el()?.querySelector('video') ?? null;

      // Initialize the player UI if not done by now
      utils.setRenewingTimeout(this, 'plyrinit', initPlyr, 0);

      // Hide the preview image
      content.placeholder?.element?.setAttribute('hidden', 'true');

      // Another attempt to play the video
      playWithDelay();
    });

    content.videojs.qualityLevels?.({})?.on('addqualitylevel', (e: any) => {
      if (e.qualityLevel?.label?.includes('max.m3u8')) {
        // This is the highest quality level
        // and guaranteed to be the last one
        return initPlyr();
      }

      // Fallback
      utils.setRenewingTimeout(this, 'plyrinit', initPlyr, 0);
    });
  }

  destroyVideo(content: VideoContent) {
    if (isVideoContent(content)) {
      // Release wake lock
      this.releaseWakeLock();

      // Destroy exoplayer
      if (nativex.has()) {
        // Add a timeout in case another video initializes
        // immediately after this one is destroyed
        setTimeout(() => nativex.destroyVideo(content.data.photo), 500);
        return;
      }

      // Destroy videojs
      content.videojs?.pause?.();
      content.videojs?.dispose?.();
      content.videojs = null;

      // Destroy plyr
      content.plyr?.elements?.container?.remove();
      content.plyr?.destroy();
      content.plyr = null;

      // Clear the video element
      if (content.element instanceof HTMLDivElement) {
        const elem = content.element;
        while (elem.lastElementChild) {
          elem.removeChild(elem.lastElementChild);
        }
      }
      content.videoElement = null;

      // Restore placeholder image
      content.placeholder?.element?.removeAttribute('hidden');
    }
  }

  initPlyr(content: VideoContent) {
    if (content.plyr || !content.videojs || !content.element) return;

    content.videoElement = content.videojs?.el()?.querySelector('video');
    if (!content.videoElement) return;

    // Retain original parent for video element
    const origParent = content.videoElement.parentElement!;

    // Populate quality list
    const qualityNums: number[] = [];
    let hasOriginal = false;
    const qualityList = content.videojs?.qualityLevels?.({});
    if (qualityList?.length) {
      for (let i = 0; i < qualityList.length; i++) {
        const { width, height, label } = qualityList[i];
        qualityNums.push(Math.min(width!, height!));
        hasOriginal ||= label?.includes('max.m3u8') ?? false;
      }
    }

    // Sort quality list descending
    qualityNums.sort((a, b) => b - a);

    // The qualityList is empty on iOS Safari
    if (!staticConfig.getSync('vod_disable') && (qualityNums.length || _m.video.videojs.browser.IS_SAFARI)) {
      qualityNums.unshift(0); // adaptive
    }

    if (hasOriginal) qualityNums.unshift(-1); // original
    if (true) qualityNums.unshift(-2); // direct

    // Create the plyr instance
    const opts: Plyr.Options = {
      i18n: {
        qualityLabel: {
          '-2': t('memories', 'Direct'),
          '-1': t('memories', 'Original'),
          '0': t('memories', 'Auto'),
        },
      },
      fullscreen: {
        enabled: true,
        // container: we need to set this after Plyr is loaded
        // since we don't initialize Plyr inside the container,
        // and this container is computed during construction
        // https://github.com/sampotts/plyr/blob/20bf5a883306e9303b325e72c9102d76cc733c47/src/js/fullscreen.js#L30
      },
      loop: {
        active: staticConfig.getSync('video_loop'),
      },
    };

    // Add quality options
    if (qualityNums) {
      opts.quality = {
        default: Number(staticConfig.getSync('video_default_quality')),
        options: qualityNums,
        forced: true,
        onChange: (quality: number) => this.changeQuality(content, quality),
      };
    }

    // Initialize Plyr and custom CSS
    const plyr = new _m.video.Plyr(content.videoElement, opts);
    const container = plyr.elements.container!;

    container.style.height = '100%';
    container.style.width = '100%';
    container.querySelectorAll('button').forEach((el) => el.classList.add('button-vue'));
    container.querySelectorAll('progress').forEach((el) => el.classList.add('vue'));
    container.style.backgroundColor = 'transparent';
    plyr.elements.wrapper!.style.backgroundColor = 'transparent';

    // Set the fullscreen element to the container
    plyr.elements.fullscreen = content.slide?.holderElement || null;

    // Done with init
    content.plyr = plyr;

    // Wait for animation to end before showing Plyr
    container.style.opacity = '0';
    setTimeout(() => {
      container.style.opacity = '1';
    }, 250);

    // Restore original parent of video element
    if (content.videoElement.parentElement !== origParent) {
      // Shouldn't happen when plyr-wrap.patch is applied
      console.error('PsVideo: Video element parent was changed. Is plyr-wrap.patch applied?');
      origParent.appendChild(content.videoElement);
    }

    // Move plyr to the slide container
    content.slide?.holderElement?.appendChild(container);

    // Add fullscreen orientation hooks
    if ((screen.orientation as any)?.lock) {
      // Store the previous orientation
      // This is because unlocking (at least on Chrome) does
      // not restore the previous orientation
      let previousOrientation: OrientationType | undefined;

      // Lock orientation when entering fullscreen
      plyr.on('enterfullscreen', async (event) => {
        const h = content.data.photo.h;
        const w = content.data.photo.w;

        if (h && w) {
          previousOrientation ||= screen.orientation.type;
          const orientation = h < w ? 'landscape' : 'portrait';

          try {
            await (screen.orientation as any).lock(orientation);
          } catch (e) {
            previousOrientation = undefined;
          }
        }
      });

      // Unlock orientation when exiting fullscreen
      plyr.on('exitfullscreen', async (event) => {
        try {
          if (previousOrientation) {
            await (screen.orientation as any).lock(previousOrientation);
            previousOrientation = undefined;
          }
        } catch (e) {
          // Ignore
        } finally {
          screen.orientation.unlock();
        }
      });
    }
  }

  changeQuality(content: VideoContent, quality: number | null) {
    if (quality === null) return;
    this.lastQuality = quality;

    // Changing the quality sometimes throws strange
    // DOMExceptions when initializing; don't let this stop
    // Plyr from being constructed altogether.
    // https://github.com/videojs/http-streaming/pull/1439
    try {
      const qualityList = content.videojs?.qualityLevels?.({});
      if (!qualityList || !content.videojs) return;

      const isHLS = content.videojs.src(undefined)?.includes('m3u8');

      if (quality === -2) {
        // Direct playback
        // Prevent any useless transcodes
        for (let i = 0; i < qualityList.length; ++i) {
          qualityList[i].enabled = false;
        }

        // Set the source to the original video
        if (isHLS) {
          this.changeSourceKeepTime(content.videojs, this.getDirectSrc(content));
        }
        return;
      } else {
        // Set source to HLS
        if (!isHLS) {
          this.changeSourceKeepTime(content.videojs, this.getHLSsrc(content));
        }
      }

      // Enable only the selected quality
      for (let i = 0; i < qualityList.length; ++i) {
        const { width, height, label } = qualityList[i];
        const pixels = Math.min(width!, height!);
        qualityList[i].enabled =
          !quality || // auto
          pixels === quality || // exact match
          ((label?.includes('max.m3u8') ?? false) && quality === -1); // max
      }
    } catch (e) {
      console.warn(e);
    }
  }

  changeSourceKeepTime(vidjs: Player, src: { src: string; type: string }) {
    const time = vidjs.currentTime();
    vidjs.src(src);
    vidjs.currentTime(time);
    this.playNoThrow(vidjs);
  }

  async playNoThrow(vidjs: Player | null) {
    try {
      await vidjs?.play();
    } catch (e) {
      // Ignore - video destroyed?
    }
  }

  onContentDestroy({ content }: PsVideoEvent) {
    this.destroyVideo(content);
  }

  onContentResize(e: PsVideoEvent & { width: number; height: number }) {
    if (isVideoContent(e.content)) {
      e.preventDefault();

      const width = e.width;
      const height = e.height;
      const content = e.content;

      if (content.element) {
        content.element.style.width = width + 'px';
        content.element.style.height = height + 'px';
      }

      // override placeholder size, so it more accurately matches the video
      const phStyle = content.placeholder?.element?.style;
      if (phStyle) {
        phStyle.transform = 'none';
        phStyle.width = width + 'px';
        phStyle.height = height + 'px';
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
    this.initVideo(content);
  }

  onContentDeactivate({ content }: PsVideoEvent) {
    this.destroyVideo(content);
  }

  onContentLoad(e: PsVideoEvent) {
    const content: PsContent = e.content;
    if (!isVideoContent(content)) return;

    // Stop default content load
    e.preventDefault();
    content.type = 'video';

    if (content.element) return;

    // Create DIV
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
