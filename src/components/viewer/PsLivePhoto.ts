import PhotoSwipe from "photoswipe";
import { getXImgElem } from "./PsImage";
import * as utils from "../../services/Utils";

export function isLiveContent(content): boolean {
  // Do not play Live Photo if the slideshow is
  // playing in full screen mode.
  if (document.fullscreenElement) {
    return false;
  }

  return Boolean(content?.data?.photo?.liveid);
}

class LivePhotoContentSetup {
  constructor(lightbox: PhotoSwipe, private options) {
    this.initLightboxEvents(lightbox);
  }

  initLightboxEvents(lightbox: PhotoSwipe) {
    lightbox.on("contentLoad", this.onContentLoad.bind(this));
    lightbox.on("contentActivate", this.onContentActivate.bind(this));
    lightbox.on("contentDeactivate", this.onContentDeactivate.bind(this));
    lightbox.on("contentAppend", this.onContentAppend.bind(this));
  }

  onContentLoad(e) {
    const content = e.content;
    if (!isLiveContent(content)) return;

    e.preventDefault();
    if (content.element) return;

    const photo = content?.data?.photo;

    const video = document.createElement("video");
    video.muted = true;
    video.autoplay = false;
    video.playsInline = true;
    video.preload = "none";
    video.src = utils.getLivePhotoVideoUrl(photo, true);

    const div = document.createElement("div");
    div.className = "memories-livephoto";
    div.appendChild(video);
    content.element = div;

    utils.setupLivePhotoHooks(video);

    const img = getXImgElem(content, () => content.onLoaded());
    div.appendChild(img);

    content.element = div;
  }

  onContentActivate({ content }) {
    if (isLiveContent(content) && content.element) {
      const video = content.element.querySelector("video");
      if (video) {
        video.currentTime = 0;
        video.play();
      }
    }
  }

  onContentDeactivate({ content }) {
    if (isLiveContent(content) && content.element) {
      content.element.querySelector("video")?.pause();
    }
  }

  onContentAppend(e) {
    if (isLiveContent(e.content)) {
      e.preventDefault();
      e.content.isAttached = true;
      e.content.appendImage();
    }
  }
}

export default LivePhotoContentSetup;
