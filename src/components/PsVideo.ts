import PhotoSwipe from "photoswipe";
import { generateUrl } from "@nextcloud/router";
import { loadState } from "@nextcloud/initial-state";
import axios from "@nextcloud/axios";

import videojs from "video.js";
import "video.js/dist/video-js.min.css";
import "videojs-contrib-quality-levels";
import "videojs-hls-quality-selector";

const config_noTranscode = loadState(
  "memories",
  "notranscode",
  <string>"UNSET"
) as boolean | string;

// Generate client id for this instance
// Does not need to be cryptographically secure
const clientId = Math.random().toString(36).substring(2, 15).padEnd(12, "0");

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
        const content = <any>e.slide.content;

        if (!e.slide.isActive) {
          e.preventDefault();
        } else if (content.videoElement) {
          const fileid = content.data.photo.fileid;

          // Create hls sources if enabled
          let sources: any[] = [];
          const baseUrl = generateUrl(
            `/apps/memories/api/video/transcode/${clientId}/${fileid}`
          );

          if (!config_noTranscode) {
            sources.push({
              src: `${baseUrl}/index.m3u8`,
              type: "application/x-mpegURL",
            });
          }

          sources.push({
            src: e.slide.data.src,
          });

          const overrideNative = !videojs.browser.IS_SAFARI;
          content.videojs = videojs(content.videoElement, {
            fill: true,
            autoplay: true,
            controls: true,
            sources: sources,
            preload: "metadata",
            playbackRates: [0.5, 1, 1.5, 2],
            responsive: true,
            html5: {
              vhs: {
                overrideNative: overrideNative,
                withCredentials: false,
              },
              nativeAudioTracks: !overrideNative,
              nativeVideoTracks: !overrideNative,
            },
          });

          content.videojs.on("error", () => {
            if (content.videojs.error().code === 4) {
              if (content.videojs.src().includes("m3u8")) {
                // HLS could not be streamed
                console.error("Video.js: HLS stream could not be opened.");
                content.videojs.src({
                  src: e.slide.data.src,
                });
                this.updateRotation(content, 0);
              }
            }
          });

          content.videojs.qualityLevels();
          content.videojs.hlsQualitySelector({
            displayCurrentQuality: true,
          });

          setTimeout(() => {
            content.videojs
              .contentEl()
              .querySelectorAll("button")
              .forEach((b: HTMLButtonElement) => {
                b.classList.add("button-vue");
              });
          }, 500);

          // Get correct orientation
          axios
            .get<any>(
              generateUrl("/apps/memories/api/image/info/{id}", {
                id: content.data.photo.fileid,
              })
            )
            .then((response) => {
              content.data.exif = response.data?.exif;
              this.updateRotation(content);
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

  updateRotation(content, val?: number) {
    const rotation = val ?? Number(content.data.exif?.Rotation);
    const shouldRotate = content.videojs?.src().includes("m3u8");
    if (rotation && shouldRotate) {
      let transform = `rotate(${rotation}deg)`;

      if (rotation === 90 || rotation === 270) {
        content.videoElement.style.width = content.element.style.height;
        content.videoElement.style.height = content.element.style.width;

        transform = `translateY(-${content.element.style.width}) ${transform}`;
        content.videoElement.style.transformOrigin = "bottom left";
      }

      content.videoElement.style.transform = transform;
    } else {
      content.videoElement.style.transform = "none";
      content.videoElement.style.width = "100%";
      content.videoElement.style.height = "100%";
    }
  }

  onContentDestroy({ content }) {
    if (isVideoContent(content)) {
      if (content._videoPosterImg) {
        content._videoPosterImg.onload = content._videoPosterImg.onerror = null;
        content._videoPosterImg = null;
      }

      if (content.videojs) {
        content.videojs.dispose();
        content.videojs = null;
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

      this.updateRotation(content);
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

    if (config_noTranscode === "UNSET") {
      content.element = document.createElement("div");
      content.element.innerHTML =
        "Video not configured. Run occ memories:video-setup";
      content.element.style.color = "red";
      content.element.style.display = "flex";
      content.element.style.alignItems = "center";
      content.element.style.justifyContent = "center";
      content.onLoaded();
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
