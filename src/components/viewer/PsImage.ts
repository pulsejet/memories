import PhotoSwipe from "photoswipe";
import { isVideoContent } from "./PsVideo";
import { isLiveContent } from "./PsLivePhoto";
import { fetchImage } from "../frame/XImgCache";

export function getXImgElem(
  content: any,
  onLoad: () => void
): HTMLImageElement {
  const img = document.createElement("img");
  img.classList.add("pswp__img");
  img.style.visibility = "hidden";

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
    };
  });

  return img;
}

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

    // Insert image throgh XImgCache
    e.preventDefault();
    e.content.element = getXImgElem(e.content, () => e.content.onLoaded());
  }

  onContentLoadImage(e) {
    if (isVideoContent(e.content) || isLiveContent(e.content)) return;
    e.preventDefault();
  }
}
