import PhotoSwipe from "photoswipe";
import { isVideoContent } from "./PsVideo";
import { isLiveContent } from "./PsLivePhoto";
import { fetchImage } from "../frame/XImgCache";

export default class ImageContentSetup {
  constructor(lightbox: PhotoSwipe) {
    this.initLightboxEvents(lightbox);
  }

  initLightboxEvents(lightbox: PhotoSwipe) {
    lightbox.on("contentLoad", this.onContentLoad.bind(this));
    lightbox.on("contentLoadImage", this.onContentLoadImage.bind(this));
  }

  onContentLoad(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Don't insert default image
    e.preventDefault();
    const content = e.content;
    const img = document.createElement("img");
    img.classList.add("pswp__img");
    img.style.visibility = "hidden";
    content.element = img;

    // Fetch with Axios
    fetchImage(content.data.src).then((blob) => {
      // Check if destroyed already
      if (!content.element) return;

      // Insert image
      const blobUrl = URL.createObjectURL(blob);
      img.src = blobUrl;
      img.onerror = img.onload = () => {
        img.style.visibility = "visible";
        content.onLoaded();
        URL.revokeObjectURL(blobUrl);
      };
    });
  }

  onContentLoadImage(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();
  }
}
