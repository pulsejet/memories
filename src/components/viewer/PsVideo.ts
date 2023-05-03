import PhotoSwipe from 'photoswipe';
import staticConfig from '../../services/static-config';
import { showError } from '@nextcloud/dialogs';
import { translate as t } from '@nextcloud/l10n';
import { getCurrentUser } from '@nextcloud/auth';
import axios from '@nextcloud/axios';

import { API } from '../../services/API';
import type { PsContent, PsEvent } from './types';

import Player from 'video.js/dist/types/player';
import { QualityLevelList } from 'videojs-contrib-quality-levels';

type VideoContent = PsContent & {
  videoElement: HTMLVideoElement | null;
  videojs:
    | (Player & {
        qualityLevels?: () => QualityLevelList;
      })
    | null;
  plyr: globalThis.Plyr | null;
};

type PsVideoEvent = PsEvent & {
  content: VideoContent;
};

/**
 * Check if slide has video content
 */
export function isVideoContent(content: any): content is VideoContent {
  return content?.data?.type === 'video';
}

class VideoContentSetup {
  constructor(
    lightbox: PhotoSwipe,
    private options: {
      preventDragOffset: number;
    }
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
      if (isVideoContent(e.slide)) {
        const content = <any>e.slide.content;
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

    // Prevent double loading
    content.videojs = {} as any;

    // Load videojs scripts
    if (!globalThis.vidjs) {
      await import('../../services/videojs');
    }

    // Create video element
    content.videoElement = document.createElement('video');
    content.videoElement.className = 'video-js';
    content.videoElement.setAttribute('poster', content.data.msrc);
    content.videoElement.setAttribute('preload', 'none');
    content.videoElement.setAttribute('controls', '');
    content.videoElement.setAttribute('playsinline', '');

    // Add the video element to the actual container
    content.element?.appendChild(content.videoElement);

    // Create hls sources if enabled
    const sources: {
      src: string;
      type: string;
    }[] = [];

    if (!staticConfig.getSync('vod_disable')) {
      sources.push(this.getHLSsrc(content));
    }

    sources.push(this.getDirectSrc(content));

    const overrideNative = !vidjs.browser.IS_SAFARI;
    const vjs = (content.videojs = vidjs(content.videoElement, {
      fill: true,
      autoplay: true,
      controls: false,
      sources: sources,
      preload: 'metadata',
      playbackRates: [0.5, 1, 1.5, 2],
      responsive: true,
      html5: {
        vhs: {
          overrideNative: overrideNative,
          withCredentials: false,
          useBandwidthFromLocalStorage: true,
          useNetworkInformationApi: true,
          limitRenditionByPlayerDimensions: false,
        },
        nativeAudioTracks: !overrideNative,
        nativeVideoTracks: !overrideNative,
      },
    }));

    // Fallbacks
    let directFailed = false;
    let hlsFailed = false;

    vjs.on('error', () => {
      if (vjs.src(undefined)?.includes('m3u8')) {
        hlsFailed = true;
        console.warn('PsVideo: HLS stream could not be opened.');

        if (getCurrentUser()?.isAdmin) {
          showError(t('memories', 'Transcoding failed, check Nextcloud logs.'));
        }

        if (!directFailed) {
          console.warn('PsVideo: Trying direct video stream');
          vjs.src(this.getDirectSrc(content));
          this.updateRotation(content, 0);
        }
      } else {
        directFailed = true;
        console.warn('PsVideo: Direct video stream could not be opened.');

        if (!hlsFailed && !staticConfig.getSync('vod_disable')) {
          console.warn('PsVideo: Trying HLS stream');
          vjs.src(this.getHLSsrc(content));
        }
      }
    });

    // Play the video (hopefully)
    const playWithDelay = () => setTimeout(() => content.videojs?.play(), 100);
    playWithDelay();

    let canPlay = false;
    content.videojs.on('canplay', () => {
      canPlay = true;
      this.updateRotation(content); // also gets the correct video elem as a side effect

      // Initialize the player UI
      window.setTimeout(() => this.initPlyr(content), 0);

      // Hide the preview image
      content.placeholder?.element?.setAttribute('hidden', 'true');

      // Another attempt to play the video
      playWithDelay();
    });

    content.videojs.qualityLevels?.()?.on('addqualitylevel', (e) => {
      if (e.qualityLevel?.label?.includes('max.m3u8')) {
        // This is the highest quality level
        // and guaranteed to be the last one
        this.initPlyr(content);
      }

      // Fallback
      window.setTimeout(() => this.initPlyr(content), 0);
    });

    // Get correct orientation
    if (!content.data.photo.imageInfo) {
      const url = API.IMAGE_INFO(content.data.photo.fileid);
      axios.get<any>(url).then((response) => {
        content.data.photo.imageInfo = response.data;

        // Update only after video is ready
        // Otherwise the poster image is rotated
        if (canPlay) this.updateRotation(content);
      });
    } else {
      if (canPlay) this.updateRotation(content);
    }
  }

  destroyVideo(content: VideoContent) {
    if (isVideoContent(content)) {
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
    let qualityList = content.videojs?.qualityLevels?.();
    let qualityNums: number[] | undefined;
    if (qualityList && qualityList.length >= 1) {
      const s = new Set<number>();
      let hasMax = false;
      for (let i = 0; i < qualityList?.length; i++) {
        const { width, height, label } = qualityList[i];
        s.add(Math.min(width!, height!));

        if (label?.includes('max.m3u8')) {
          hasMax = true;
        }
      }

      qualityNums = Array.from(s).sort((a, b) => b - a);
      qualityNums.unshift(0);
      if (hasMax) {
        qualityNums.unshift(-1);
      }
      qualityNums.unshift(-2);
    }

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
    };

    // Add quality options
    if (qualityNums) {
      opts.quality = {
        default: Number(staticConfig.getSync('video_default_quality')),
        options: qualityNums,
        forced: true,
        onChange: (quality: number) => {
          qualityList = content.videojs?.qualityLevels?.();
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
              content.videojs.src(this.getDirectSrc(content));
            }
            return;
          } else {
            // Set source to HLS
            if (!isHLS) {
              content.videojs.src(this.getHLSsrc(content));
            }
          }

          // Enable only the selected quality
          for (let i = 0; i < qualityList.length; ++i) {
            const { width, height, label } = qualityList[i];
            const pixels = Math.min(width!, height!);
            qualityList[i].enabled =
              !quality || // auto
              pixels === quality || // exact match
              (label?.includes('max.m3u8') && quality === -1); // max
          }
        },
      };
    }

    // Initialize Plyr and custom CSS
    const plyr = new Plyr(content.videoElement, opts);
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
    origParent.appendChild(content.videoElement);
    // Move plyr to the slide container
    content.slide?.holderElement?.appendChild(container);

    // Add fullscreen orientation hooks
    if (screen.orientation?.lock) {
      // Store the previous orientation
      // This is because unlocking (at least on Chrome) does
      // not restore the previous orientation
      let previousOrientation: OrientationLockType | undefined;

      // Lock orientation when entering fullscreen
      plyr.on('enterfullscreen', async (event) => {
        const rotation = this.updateRotation(content);
        const exif = content.data.photo.imageInfo?.exif;
        const h = Number(exif?.ImageHeight || 0);
        const w = Number(exif?.ImageWidth || 1);

        if (h && w) {
          previousOrientation ||= screen.orientation.type;
          const orientation = h < w && !rotation ? 'landscape' : 'portrait';

          try {
            await screen.orientation.lock(orientation);
          } catch (e) {
            previousOrientation = undefined;
          }
        }
      });

      // Unlock orientation when exiting fullscreen
      plyr.on('exitfullscreen', async (event) => {
        try {
          if (previousOrientation) {
            await screen.orientation.lock(previousOrientation);
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

  updateRotation(content: VideoContent, val?: number): boolean {
    if (!content.videojs) return false;

    content.videoElement = content.videojs.el()?.querySelector('video');
    if (!content.videoElement) return false;

    const photo = content.data.photo;
    const exif = photo.imageInfo?.exif;
    const rotation = val ?? Number(exif?.Rotation || 0);
    const shouldRotate = content.videojs?.src(undefined)?.includes('m3u8');

    if (rotation && shouldRotate) {
      let transform = `rotate(${rotation}deg)`;
      const hasRotation = rotation === 90 || rotation === 270;

      if (hasRotation) {
        content.videoElement.style.width = content.element!.style.height;
        content.videoElement.style.height = content.element!.style.width;

        transform = `translateY(-${content.element!.style.width}) ${transform}`;
        content.videoElement.style.transformOrigin = 'bottom left';
      }

      content.videoElement.style.transform = transform;

      return hasRotation;
    } else {
      content.videoElement.style.transform = 'none';
      content.videoElement.style.width = '100%';
      content.videoElement.style.height = '100%';
    }

    return false;
  }

  onContentDestroy({ content }: PsVideoEvent) {
    this.destroyVideo(content);
  }

  onContentResize(e) {
    if (isVideoContent(e.content)) {
      e.preventDefault();

      const width = e.width;
      const height = e.height;
      const content = e.content;

      if (content.element) {
        content.element.style.width = width + 'px';
        content.element.style.height = height + 'px';
      }

      if (content.slide && content.slide.placeholder) {
        // override placeholder size, so it more accurately matches the video
        const placeholderElStyle = content.slide.placeholder.element.style;
        placeholderElStyle.transform = 'none';
        placeholderElStyle.width = width + 'px';
        placeholderElStyle.height = height + 'px';
      }

      this.updateRotation(content);
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
}

export default VideoContentSetup;
