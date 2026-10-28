import { isVideoContent } from './PsVideo';
import { isLiveContent } from './PsLivePhoto';
import * as ximg from '@components/frame/XImgCache';
import * as nativex from '@native';
import { isLikelySamePhoto } from '@services/utils/photo';

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
    lightbox.on('contentLoad', (e) => this.onContentLoad(e as unknown as PsEvent));
    lightbox.on('contentLoadImage', (e) => this.onContentLoadImage(e as unknown as PsEvent));
    lightbox.on('contentDestroy', (e) => this.onContentDestroy(e as unknown as PsEvent));
    lightbox.on('destroy', () => this.onDestroy());
    lightbox.on('zoomPanUpdate', (e) => this.zoomPanUpdate(e as unknown as { slide: PsSlide }));
    lightbox.on('slideActivate', (e) => this.slideActivate(e.slide as unknown as PsSlide));
    lightbox.on('slideDeactivate', (e) => this.slideDeactivate(e.slide as unknown as PsSlide));
    lightbox.addFilter('isContentLoading', (l, c) => this.isContentLoading(l, c as unknown as PsContent));
    lightbox.addFilter('placeholderSrc', (s, c) => this.placeholderSrc(s, c as unknown as PsContent));
  }

  private isContentLoading(isLoading: boolean, content: PsContent) {
    return isLoading || this.loading > 0;
  }

  public onContentLoad(e: PsEvent) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();

    // Create container
    const container = document.createElement('div');
    container.classList.add('pswp__img');
    e.content.element = container;

    // Lazy create the preview element
    void this.createPreviewElement(e.content);
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
    for (const fileid of this.stickySrcs.keys()) {
      this.setUnsticky(fileid);
    }
  }

  private placeholderSrc(placeholderSrc: string | false, content: PsContent) {
    // We can't load msrc unless it is a blob
    // since these requests are not cached, leading to race conditions
    // with the loading of the actual images.
    // Sample is for OnThisDay, where msrc isn't blob
    if (content.data.msrc?.startsWith('blob:')) {
      return content.data.msrc;
    }

    return placeholderSrc;
  }

  public async createPreviewElement(content: PsContent) {
    const img = document.createElement('img');
    img.classList.add('ximg', 'ximg--preview');

    try {
      // Fetch with Axios
      const blobUrl = await ximg.fetchImage(content.data.src);

      // Prevent garbage collection of the blob URL
      if (content.element) {
        this.setSticky(content.data.photo.fileid, blobUrl);
      }

      // Force decoding off-screen.
      img.src = blobUrl;
      await img.decode();
    } catch (e) {
      img.src = errorsvg;
      console.error('Error loading PsImage:', e);
    }

    // Add the preview image to the main content.
    if (!content.element) return;
    content.element.appendChild(img);
    content.onLoaded();

    // Create a lightweight preview for closing animation.
    // Especially relevant when the main preview is U-HDR.
    void this.createLightPreviewElement(content);
  }

  private async createLightPreviewElement(content: PsContent) {
    if (!content.data.msrc?.startsWith('blob:')) return;

    try {
      const thumbImg = document.createElement('img');
      thumbImg.src = content.data.msrc;
      thumbImg.classList.add('ximg', 'ximg--thumbnail');
      await thumbImg.decode();

      if (!content.element) return;
      content.element.prepend(thumbImg);
    } catch (e) {
      console.error('Error creating light preview:', e);
    }
  }

  public zoomPanUpdate({ slide }: { slide: PsSlide }) {
    if (!slide.data.highSrc.length || slide.data.highSrcCond !== 'zoom') return;

    // Check if zoomed in enough to load the full image.
    // For small images, we check that there is at least a 10% zoom to avoid
    // loading the full sized image immediately when the user opens viewer.
    const isZoomedIn = slide.currZoomLevel >= 1.1 * slide.zoomLevels.initial;
    const isOverSecondary = slide.currZoomLevel >= slide.zoomLevels.secondary;

    if (isZoomedIn && isOverSecondary) {
      this.loadFullImage(slide);
    }
  }

  public slideActivate(slide: PsSlide) {
    if (nativex.viewImageIsEnabled() && !slide.data.nxImageState && !isVideoContent(slide.content)) {
      const photo = slide.data.photo;
      let localUri = photo.local_uri;
      if (!localUri && isLikelySamePhoto(photo, photo.local_photo)) {
        localUri = photo.local_photo.local_uri;
      }
      void this.nxViewImageUpload(slide, slide.data.src, localUri);
    }

    if (slide.data.highSrcCond === 'always') {
      void this.loadFullImage(slide);
    }
  }

  public slideDeactivate(slide: PsSlide) {
    slide.data.nxImageState = undefined;

    // The native view is actually destroyed, so we'll need to load
    // full res image again if we come back to this slide.
    if (slide.data.highSrcCondRestore) {
      slide.data.highSrcCond = slide.data.highSrcCondRestore;
      slide.data.highSrcCondRestore = undefined;
    }
  }

  private async loadFullImage(slide: PsSlide) {
    if (!slide.data.highSrc.length || slide.data.highSrcCond === 'never') return;

    // Reset view on deactivate on native (no preservation).
    if (nativex.viewImageIsEnabled()) {
      slide.data.highSrcCondRestore = slide.data.highSrcCond;
    }

    // Don't load again
    slide.data.highSrcCond = 'never';

    this.loading++;
    this.lightbox.ui?.updatePreloaderVisibility();

    for (const src of slide.data.highSrc) {
      try {
        // Do this first always to ensure the image exists.
        const blobSrc = await ximg.fetchImage(src);

        // If we have a native view upload there directly.
        if (nativex.viewImageIsEnabled()) {
          if (await this.nxViewImageUpload(slide, src, undefined)) {
            break; // success
          }
          continue;
        }

        // Create HTML image and pre-decode.
        const image = new Image();
        image.src = blobSrc;
        image.classList.add('ximg', 'ximg--full');
        await image.decode();

        // Check if destroyed already
        const container = slide.content.element;
        if (!container) break;

        // Show image and prevent garbage collection.
        container.appendChild(image);

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

  /** Fetch the preview blob and POST it. */
  private async nxViewImageUpload(slide: PsSlide, remote: string, local: string | undefined) {
    if (slide.data.nxImageState !== 'loaded') {
      slide.data.nxImageState = 'loading';
    }
    const onload = () => {
      if (!slide.data.nxImageState) return; // slide deactivated
      slide.data.nxImageState = 'loaded';
      this.lightbox.dispatch('initialZoomPan');
    };

    if (local) {
      try {
        await nativex.viewImageLocal(local);
        slide.data.highSrcCond = 'never'; // already max-res
        onload();
        return true;
      } catch (e) {
        console.warn('Failed to view image locally.', e);
      }
    }

    try {
      const blobUrl = await ximg.fetchImage(remote);
      const res = await fetch(blobUrl);
      const blob = await res.blob();
      await nativex.viewImageUpload(blob);
      onload();
      return true;
    } catch (e) {
      console.error('Error uploading image to native viewer:', e);
      if (slide.data.nxImageState !== 'loaded') {
        slide.data.nxImageState = 'error';
      }
    }

    return false;
  }
}
