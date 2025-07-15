import { isVideoContent } from './PsVideo';
import { isLiveContent } from './PsLivePhoto';
import * as ximg from '../frame/XImgCache';

import type PhotoSwipe from 'photoswipe';
import type { PsContent, PsEvent, PsSlide } from './types';

import errorsvg from '@assets/error.svg';

export default class ImageContentSetup {
  private loading = 0;

  /**
   * Loaded slides must have sticky blob URLs to enable sharing
   * using context menu. This map keeps track of the sticky URLs.
   */
  private stickySrcs = new Map<number, string>();

  constructor(private lightbox: PhotoSwipe) {
    lightbox.on('contentLoad', this.onContentLoad.bind(this));
    lightbox.on('contentLoadImage', this.onContentLoadImage.bind(this));
    lightbox.on('contentDestroy', this.onContentDestroy.bind(this));
    lightbox.on('destroy', this.onDestroy.bind(this));
    lightbox.on('zoomPanUpdate', this.zoomPanUpdate.bind(this));
    lightbox.on('slideActivate', this.slideActivate.bind(this));
    lightbox.addFilter('isContentLoading', this.isContentLoading.bind(this));
    lightbox.addFilter('placeholderSrc', this.placeholderSrc.bind(this));
  }

  private isContentLoading(isLoading: boolean, content: PsContent) {
    return isLoading || this.loading > 0;
  }

  public onContentLoad(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Insert image throgh XImgCache
    e.preventDefault();
    e.content.element = this.getXImgElem(e.content, () => e.content.onLoaded());
  }

  public onContentLoadImage(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();
  }

  public onContentDestroy(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Make sure the blob can be garbage collected when the slide is destroyed.
    this.setUnsticky(e.content.data.photo.fileid);
  }

  public onDestroy() {
    // When the photoswipe instance is destroyed, make sure
    // all sticky URLs are released. This will prevent memory leaks.
    for (const fileid of Array.from(this.stickySrcs.keys())) {
      this.setUnsticky(fileid);
    }
  }

  private placeholderSrc(placeholderSrc: string, content: PsContent) {
    // We can't load msrc unless it is a blob
    // since these requests are not cached, leading to race conditions
    // with the loading of the actual images.
    // Sample is for OnThisDay, where msrc isn't blob
    if (content.data.msrc?.startsWith('blob:')) {
      return content.data.msrc;
    }

    return placeholderSrc;
  }

  public getXImgElem(content: PsContent, onLoad: () => void): HTMLImageElement {
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

        // Prevent garbage collection of the blob URL
        this.setSticky(content.data.photo.fileid, url);
      }
    };

    // Fetch with Axios
    ximg
      .fetchImage(content.data.src)
      .then(src)
      .catch((e) => {
        src(errorsvg);
        console.error('Error loading PsImage:', e);
      });

    return img;
  }

  public zoomPanUpdate({ slide }: { slide: PsSlide }) {
    if (!slide.data.highSrc.length || slide.data.highSrcCond !== 'zoom') return;

    if (slide.currZoomLevel >= slide.zoomLevels.secondary) {
      this.loadFullImage(slide);
    }
  }

  public slideActivate() {
    const slide = this.lightbox.currSlide as unknown as PsSlide | undefined;
    if (slide?.data.highSrcCond === 'always') {
      this.loadFullImage(slide);
    }
  }

  private async loadFullImage(slide: PsSlide) {
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
        const blobSrc = await ximg.fetchImage(src);

        // Check if destroyed already
        if (!slide.content.element) return;

        // Show image and prevent garbage collection.
        img.src = blobSrc;

        // If the preview was already loaded, then it will automatically be released.
        this.setSticky(slide.data.photo.fileid, blobSrc);

        break; // success
      } catch {
        // go on to next image
      }
    }

    this.loading--;
    this.lightbox.ui?.updatePreloaderVisibility();
  }

  // Mark a sticky url for a given fileid
  // Prevents the blob from being garbage collected
  private setSticky(fileid: number, src: string) {
    this.setUnsticky(fileid);
    ximg.sticky(src, +1);
    this.stickySrcs.set(fileid, src);
  }

  // Unstick a url for a given fileid
  // Frees up the blob URL, and it may be garbage collected
  private setUnsticky(fileid: number) {
    const src = this.stickySrcs.get(fileid);
    if (!src) return;
    ximg.sticky(src, -1);
    this.stickySrcs.delete(fileid);
  }
}
