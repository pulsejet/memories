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
  }

  onContentLoad(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;

    // Don't insert default image
    e.preventDefault();
    const content = e.content;
    const img = document.createElement("img");
    img.classList.add("pswp__img");
    content.element = img;

    // Fetch with Axios
    fetchImage(content.data.src).then((blob) => {
      const blobUrl = URL.createObjectURL(blob);
      img.src = blobUrl;
      img.onerror = img.onload = () => {
        content.onLoaded();
        URL.revokeObjectURL(blobUrl);
      };
    });
  }
}
