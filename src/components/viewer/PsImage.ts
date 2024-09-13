import { isVideoContent } from './PsVideo';
import { isLiveContent } from './PsLivePhoto';
import { fetchImage } from '../frame/XImgCache';

import type PhotoSwipe from 'photoswipe';
import type { PsContent, PsEvent, PsSlide } from './types';

import errorsvg from '@assets/error.svg';

export default class ImageContentSetup {
  private loading = 0;

  constructor(private lightbox: PhotoSwipe) {
    lightbox.on('contentLoad', this.onContentLoad.bind(this));
    lightbox.on('contentLoadImage', this.onContentLoadImage.bind(this));
    lightbox.on('zoomPanUpdate', this.zoomPanUpdate.bind(this));
    lightbox.on('slideActivate', this.slideActivate.bind(this));
    lightbox.addFilter('isContentLoading', this.isContentLoading.bind(this));
    lightbox.addFilter('placeholderSrc', this.placeholderSrc.bind(this));
  }

  isContentLoading(isLoading: boolean, content: PsContent) {
    return isLoading || this.loading > 0;
  }

  onContentLoad(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Insert image throgh XImgCache
    e.preventDefault();
    e.content.element = this.getXImgElem(e.content, () => e.content.onLoaded());
  }

  onContentLoadImage(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();
  }

  placeholderSrc(placeholderSrc: string, content: PsContent) {
    // We can't load msrc unless it is a blob
    // since these requests are not cached, leading to race conditions
    // with the loading of the actual images.
    // Sample is for OnThisDay, where msrc isn't blob
    if (content.data.msrc?.startsWith('blob:')) {
      return content.data.msrc;
    }

    return placeholderSrc;
  }

  getXImgElem(content: PsContent, onLoad: () => void): HTMLImageElement {
    const img = document.createElement('img');
    img.classList.add('pswp__img', 'ximg');
    img.style.visibility = 'hidden';

    // Callback on error or load
    img.onerror = img.onload = () => {
      img.onerror = img.onload = null;
      img.style.visibility = 'visible';
      onLoad();
      this.slideActivate();
    };

    // Set src on element if content is available
    const src = (url: string) => {
      if (content.element) {
        img.src = url;
      }
    };

    // Fetch with Axios
    fetchImage(content.data.src)
      .then(src)
      .catch((e) => {
        src(errorsvg);
        console.error('Error loading PsImage:', e);
      });

    return img;
  }

  zoomPanUpdate({ slide }: { slide: PsSlide }) {
    if (!slide.data.highSrc.length || slide.data.highSrcCond !== 'zoom') return;

    if (slide.currZoomLevel >= slide.zoomLevels.secondary) {
      this.loadFullImage(slide);
    }
  }

  slideActivate() {
    const slide = this.lightbox.currSlide as unknown as PsSlide | undefined;
    if (slide?.data.highSrcCond === 'always') {
      this.loadFullImage(slide);
    }
  }

  async loadFullImage(slide: PsSlide) {
    if (!slide.data.highSrc.length) return;

    // Get ximg element
    const img = slide.holderElement?.querySelector('.ximg:not(.ximg--full)') as HTMLImageElement;
    if (!img) return;

    // Don't load again
    slide.data.highSrcCond = 'never';

    // Load full image at secondary zoom level
    img.classList.add('ximg--full');

    this.loading++;
    this.lightbox.ui?.updatePreloaderVisibility();

    for (const src of slide.data.highSrc) {
      try {
        const blobSrc = await fetchImage(src);

        // Check if destroyed already
        if (!slide.content.element) return;

        img.src = blobSrc;
        break; // success
      } catch {
        // go on to next image
      }
    }

    this.loading--;
    this.lightbox.ui?.updatePreloaderVisibility();
  }
}
