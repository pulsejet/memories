import PhotoSwipe from "photoswipe";
import videojs from "video.js";
import "video.js/dist/video-js.min.css";

/**
 * Check if slide has video content
 *
 * @param {Slide|Content} content Slide or Content object
 * @returns Boolean
 */
function isVideoContent(content): boolean {
  return content?.data?.type === "video";
}

class VideoContentSetup {
  constructor(lightbox: PhotoSwipe, private options) {
    this.initLightboxEvents(lightbox);
    lightbox.on("init", () => {
      this.initPswpEvents(lightbox);
    });
  }

  initLightboxEvents(lightbox: PhotoSwipe) {
    lightbox.on("contentLoad", this.onContentLoad.bind(this));
    lightbox.on("contentDestroy", this.onContentDestroy.bind(this));
    lightbox.on("contentActivate", this.onContentActivate.bind(this));
    lightbox.on("contentDeactivate", this.onContentDeactivate.bind(this));
    lightbox.on("contentAppend", this.onContentAppend.bind(this));
    lightbox.on("contentResize", this.onContentResize.bind(this));

    lightbox.addFilter(
      "isKeepingPlaceholder",
      this.isKeepingPlaceholder.bind(this)
    );
    lightbox.addFilter("isContentZoomable", this.isContentZoomable.bind(this));
    lightbox.addFilter(
      "useContentPlaceholder",
      this.useContentPlaceholder.bind(this)
    );

    lightbox.addFilter("domItemData", (itemData, element, linkEl) => {
      return itemData;
    });
  }

  initPswpEvents(pswp: PhotoSwipe) {
    // Prevent draggin when pointer is in bottom part of the video
    // todo: add option for this
    pswp.on("pointerDown", (e) => {
      const slide = pswp.currSlide;
      if (isVideoContent(slide) && this.options.preventDragOffset) {
        const origEvent = e.originalEvent;
        if (origEvent.type === "pointerdown") {
          const videoHeight = Math.ceil(slide.height * slide.currZoomLevel);
          const verticalEnding = videoHeight + slide.bounds.center.y;
          const pointerYPos = origEvent.pageY - pswp.offset.y;
          if (
            pointerYPos > verticalEnding - this.options.preventDragOffset &&
            pointerYPos < verticalEnding
          ) {
            e.preventDefault();
          }
        }
      }
    });

    // do not append video on nearby slides
    pswp.on("appendHeavy", (e) => {
      if (isVideoContent(e.slide)) {
        if (!e.slide.isActive) {
          e.preventDefault();
        } else {
          const content = <any>e.slide.content;
          content.videojs = videojs(content.videoElement, {
            fluid: true,
            autoplay: true,
            controls: true,
            sources: [{ src: e.slide.data.src }],
            preload: "metadata",
            inactivityTimeout: 0,
            html5: {
              vhs: {
                withCredentials: true,
              },
            },
          });
        }
      }
    });

    pswp.on("close", () => {
      if (isVideoContent(pswp.currSlide.content)) {
        // Switch from zoom to fade closing transition,
        // as zoom transition is choppy for videos
        if (
          !pswp.options.showHideAnimationType ||
          pswp.options.showHideAnimationType === "zoom"
        ) {
          pswp.options.showHideAnimationType = "fade";
        }

        // pause video when closing
        this.pauseVideo(pswp.currSlide.content);
      }
    });
  }

  onContentDestroy({ content }) {
    if (isVideoContent(content)) {
      if (content._videoPosterImg) {
        content._videoPosterImg.onload = content._videoPosterImg.onerror = null;
        content._videoPosterImg = null;
      }
    }
  }

  onContentResize(e) {
    if (isVideoContent(e.content)) {
      e.preventDefault();

      const width = e.width;
      const height = e.height;
      const content = e.content;

      if (content.element) {
        content.element.style.width = width + "px";
        content.element.style.height = height + "px";
      }

      if (content.slide && content.slide.placeholder) {
        // override placeholder size, so it more accurately matches the video
        const placeholderElStyle = content.slide.placeholder.element.style;
        placeholderElStyle.transform = "none";
        placeholderElStyle.width = width + "px";
        placeholderElStyle.height = height + "px";
      }
    }
  }

  isKeepingPlaceholder(isZoomable, content) {
    if (isVideoContent(content)) {
      return false;
    }
    return isZoomable;
  }

  isContentZoomable(isZoomable, content) {
    if (isVideoContent(content)) {
      return false;
    }
    return isZoomable;
  }

  onContentActivate({ content }) {
    if (isVideoContent(content) && this.options.autoplay) {
      this.playVideo(content);
    }
  }

  onContentDeactivate({ content }) {
    if (isVideoContent(content)) {
      this.pauseVideo(content);
    }
  }

  onContentAppend(e) {
    if (isVideoContent(e.content)) {
      e.preventDefault();
      e.content.isAttached = true;
      e.content.appendImage();
    }
  }

  onContentLoad(e) {
    const content = e.content; // todo: videocontent

    if (!isVideoContent(e.content)) {
      return;
    }

    // stop default content load
    e.preventDefault();

    if (content.element) {
      return;
    }

    content.state = "loading";
    content.type = "video"; // TODO: move this to pswp core?

    content.videoElement = document.createElement("video");
    content.videoElement.className = "video-js";
    content.videoElement.setAttribute("controls", "true");

    if (this.options.videoAttributes) {
      for (let key in this.options.videoAttributes) {
        content.videoElement.setAttribute(
          key,
          this.options.videoAttributes[key] || ""
        );
      }
    }

    content.element = document.createElement("div");
    content.element.style.position = "absolute";
    content.element.style.left = 0;
    content.element.style.top = 0;
    content.element.style.width = "100%";
    content.element.style.height = "100%";

    // content.videoElement.setAttribute("poster", content.data.msrc);
    // this.preloadVideoPoster(content, content.data.msrc);
    content.onLoaded();

    content.element.appendChild(content.videoElement);
  }

  preloadVideoPoster(content, src) {
    if (!content._videoPosterImg && src) {
      content._videoPosterImg = new Image();
      content._videoPosterImg.src = src;
      if (content._videoPosterImg.complete) {
        content.onLoaded();
      } else {
        content._videoPosterImg.onload = content._videoPosterImg.onerror =
          () => {
            content.onLoaded();
          };
      }
    }
  }

  playVideo(content) {
    content.videojs?.play();
  }

  pauseVideo(content) {
    content.videojs?.pause();
  }

  useContentPlaceholder(usePlaceholder, content) {
    if (isVideoContent(content)) {
      return true;
    }
    return usePlaceholder;
  }
}

export default VideoContentSetup;
