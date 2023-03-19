import PhotoSwipe from "photoswipe";
import Slide from "photoswipe/dist/types/slide/slide";

import { isVideoContent } from "./PsVideo";
import { isLiveContent } from "./PsLivePhoto";
import { fetchImage } from "../frame/XImgCache";

export default class ImageContentSetup {
  private loading = 0;

  constructor(private lightbox: PhotoSwipe) {
    lightbox.on("contentLoad", this.onContentLoad.bind(this));
    lightbox.on("contentLoadImage", this.onContentLoadImage.bind(this));
    lightbox.on("zoomPanUpdate", this.zoomPanUpdate.bind(this));
    lightbox.on("slideActivate", this.slideActivate.bind(this));
    lightbox.addFilter("isContentLoading", this.isContentLoading.bind(this));
  }

  isContentLoading(isLoading: boolean, content: any) {
    return isLoading || this.loading > 0;
  }

  onContentLoad(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Insert image throgh XImgCache
    e.preventDefault();
    e.content.element = this.getXImgElem(e.content, () => e.content.onLoaded());
  }

  onContentLoadImage(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();
  }

  getXImgElem(content: any, onLoad: () => void): HTMLImageElement {
    const img = document.createElement("img");
    img.classList.add("pswp__img", "ximg");

    // Load thumbnail in case the user is scrolling fast
    if (content.data.msrc) {
      img.src = content.data.msrc;
    } else {
      img.style.visibility = "hidden";
    }

    // Fetch with Axios
    fetchImage(content.data.src).then((blob) => {
      // Check if destroyed already
      if (!content.element) return;

      // Insert image
      const blobUrl = URL.createObjectURL(blob);
      img.src = blobUrl;
      img.onerror = img.onload = () => {
        img.style.visibility = "visible";
        onLoad();
        URL.revokeObjectURL(blobUrl);
        img.onerror = img.onload = null;
        this.slideActivate();
      };
    });

    return img;
  }

  zoomPanUpdate({ slide }: { slide: Slide }) {
    if (!slide.data.highSrc || slide.data.highSrcCond !== "zoom") return;

    if (slide.currZoomLevel >= slide.zoomLevels.secondary) {
      this.loadFullImage(slide);
    }
  }

  slideActivate() {
    const slide = this.lightbox.currSlide;
    if (slide.data.highSrcCond === "always") {
      this.loadFullImage(slide);
    }
  }

  loadFullImage(slide: Slide) {
    if (!slide.data.highSrc) return;

    // Get ximg element
    const img = slide.holderElement?.querySelector(
      ".ximg:not(.ximg--full)"
    ) as HTMLImageElement;
    if (!img) return;

    // Load full image at secondary zoom level
    img.classList.add("ximg--full");

    this.loading++;
    this.lightbox.ui.updatePreloaderVisibility();

    fetchImage(slide.data.highSrc)
      .then((blob) => {
        // Check if destroyed already
        if (!slide.content.element) return;

        // Insert image
        const blobUrl = URL.createObjectURL(blob);
        img.onerror = img.onload = () => {
          URL.revokeObjectURL(blobUrl);
          img.onerror = img.onload = null;
        };
        img.src = blobUrl;

        // Don't load again
        slide.data.highSrcCond = "never";
      })
      .finally(() => {
        this.loading--;
        this.lightbox.ui.updatePreloaderVisibility();
      });
  }
}
