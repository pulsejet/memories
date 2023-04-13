<template>
  <div class="p-outer-super">
    <div
      class="p-outer fill-block"
      :class="{
        selected: data.flag & c.FLAG_SELECTED,
        placeholder: data.flag & c.FLAG_PLACEHOLDER,
        leaving: data.flag & c.FLAG_LEAVING,
        error: data.flag & c.FLAG_LOAD_FAIL,
      }"
    >
      <CheckCircle v-once :size="18" class="select" @click="toggleSelect" />

      <div class="video" v-if="data.flag & c.FLAG_IS_VIDEO">
        <span v-if="data.video_duration" class="time">
          {{ videoDuration }}
        </span>
        <Video :size="22" />
      </div>

      <div
        class="livephoto"
        @mouseenter.passive="playVideo"
        @mouseleave.passive="stopVideo"
      >
        <LivePhoto :size="22" v-if="data.liveid" />
      </div>

      <Star :size="22" v-if="data.flag & c.FLAG_IS_FAVORITE" />

      <div
        class="img-outer fill-block"
        :class="{
          'memories-livephoto': data.liveid,
        }"
        @contextmenu="contextmenu"
        @pointerdown.passive="$emit('pointerdown', $event)"
        @touchstart.passive="$emit('touchstart', $event)"
        @touchmove="$emit('touchmove', $event)"
        @touchend.passive="$emit('touchend', $event)"
        @touchcancel.passive="$emit('touchend', $event)"
      >
        <XImg
          v-if="src"
          ref="ximg"
          :class="['ximg', 'fill-block', `memories-thumb-${data.key}`]"
          draggable="false"
          :src="src"
          :key="data.fileid"
          @load="load"
          @error="error"
        />
        <video
          ref="video"
          v-if="videoUrl"
          :src="videoUrl"
          preload="none"
          muted
          playsinline
          disableRemotePlayback
        />
        <div class="overlay fill-block" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from "vue";

import { IDay, IPhoto } from "../../types";
import * as utils from "../../services/Utils";

import errorsvg from "../../assets/error.svg";
import CheckCircle from "vue-material-design-icons/CheckCircle.vue";
import Star from "vue-material-design-icons/Star.vue";
import Video from "vue-material-design-icons/PlayCircleOutline.vue";
import LivePhoto from "vue-material-design-icons/MotionPlayOutline.vue";

export default defineComponent({
  name: "Photo",
  components: {
    CheckCircle,
    Video,
    Star,
    LivePhoto,
  },

  props: {
    data: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
    day: {
      type: Object as PropType<IDay>,
      required: true,
    },
  },

  data: () => ({
    touchTimer: 0,
    faceSrc: null,
  }),

  watch: {
    data(newData: IPhoto, oldData: IPhoto) {
      // Copy flags relevant to this component
      if (oldData && newData) {
        newData.flag |=
          oldData.flag & (this.c.FLAG_SELECTED | this.c.FLAG_LOAD_FAIL);
      }
    },
  },

  mounted() {
    this.faceSrc = null;

    // Setup video hooks
    const video = this.$refs.video as HTMLVideoElement;
    if (video) {
      utils.setupLivePhotoHooks(video);
    }
  },

  /** Clear timers */
  beforeDestroy() {
    clearTimeout(this.touchTimer);

    // Clean up blob url if face rect was created
    if (this.faceSrc) {
      URL.revokeObjectURL(this.faceSrc);
    }
  },

  computed: {
    videoDuration(): string | null {
      if (this.data.video_duration) {
        return utils.getDurationStr(this.data.video_duration);
      }
      return null;
    },

    videoUrl(): string | null {
      if (this.data.liveid) {
        return utils.getLivePhotoVideoUrl(this.data, true);
      }
    },

    src(): string | null {
      this.data.etag; // dependency

      if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
        return null;
      } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
        return errorsvg;
      } else if (this.faceSrc) {
        return this.faceSrc;
      } else {
        return this.url();
      }
    },
  },

  methods: {
    emitSelect(data: IPhoto) {
      this.$emit("select", data);
    },

    /** Get url of the photo */
    url() {
      let base = 256;

      // Check if displayed size is larger than the image
      if (this.data.dispH > base * 0.9 && this.data.dispW > base * 0.9) {
        // Get a bigger image
        // 1. No trickery here, just get one size bigger. This is to
        //    ensure that the images can be cached even after reflow.
        // 2. Nextcloud only allows 4**x sized images, so technically
        //    this ends up being equivalent to 1024x1024.
        base = 512;
      }

      // Make the shorter dimension equal to base
      let size = base;
      if (this.data.w && this.data.h) {
        size =
          Math.floor(
            (base * Math.max(this.data.w, this.data.h)) /
              Math.min(this.data.w, this.data.h)
          ) - 1;
      }

      return utils.getPreviewUrl(this.data, false, size);
    },

    /** Set src with overlay face rect */
    async addFaceRect() {
      if (!this.data.facerect || this.faceSrc) return;

      const img = (this.$refs.ximg as any).$el as HTMLImageElement;

      // This is a hack to check if img is actually loaded.
      //   XImg loads an empty image, which may sometimes show up here
      //   If the size is less than 5px it is probably this dummy image
      //   Either way, the user cannot see anything if the image is this small
      //   so there's no point in trying to draw the face rect
      if (!img || img.naturalWidth < 5) return;

      const canvas = document.createElement("canvas");
      const context = canvas.getContext("2d");

      canvas.width = img.naturalWidth;
      canvas.height = img.naturalHeight;
      context.drawImage(img, 0, 0);
      context.strokeStyle = "#00ff00";
      context.lineWidth = 2;
      context.strokeRect(
        this.data.facerect.x * img.naturalWidth,
        this.data.facerect.y * img.naturalHeight,
        this.data.facerect.w * img.naturalWidth,
        this.data.facerect.h * img.naturalHeight
      );

      canvas.toBlob(
        (blob) => {
          this.faceSrc = URL.createObjectURL(blob);
        },
        "image/jpeg",
        0.95
      );
    },

    /** Post load tasks */
    load() {
      this.addFaceRect();
    },

    /** Error in loading image */
    error(e: any) {
      this.data.flag |= this.c.FLAG_LOAD_FAIL;
    },

    toggleSelect() {
      if (this.data.flag & this.c.FLAG_PLACEHOLDER) return;
      this.emitSelect(this.data);
    },

    contextmenu(e: Event) {
      e.preventDefault();
      e.stopPropagation();
    },

    /** Start preview video */
    playVideo() {
      if (this.$refs.video && !(this.data.flag & this.c.FLAG_SELECTED)) {
        const video = this.$refs.video as HTMLVideoElement;
        video.currentTime = 0;
        video.play();
      }
    },

    /** Stop preview video */
    stopVideo() {
      if (this.$refs.video) {
        const video = this.$refs.video as HTMLVideoElement;
        video.pause();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
  padding: 2px;
  @media (max-width: 768px) {
    padding: 1px;
  }

  transition: background-color 0.15s ease, opacity 0.2s ease-in,
    transform 0.2s ease-in;

  &.leaving {
    transform: scale(0.9);
    opacity: 0;
  }

  &.selected {
    background-color: var(--color-primary-select-light);
    background-clip: content-box;
  }

  --icon-dist: 8px;
  @media (max-width: 768px) {
    --icon-dist: 4px;
  }
}

// Distance of icon from border
$icon-half-size: 6px;
$icon-size: $icon-half-size * 2;

/* Extra icons */
.check-circle-icon.select {
  position: absolute;
  top: calc(var(--icon-dist) + 2px);
  left: calc(var(--icon-dist) + 2px);
  z-index: 100;
  border-radius: 50%;
  cursor: pointer;

  display: none;
  @media (hover: hover) {
    .p-outer:hover > & {
      display: flex;
    }
  }

  opacity: 0.7;
  &:hover,
  .p-outer.selected & {
    opacity: 1;
  }

  // Extremely ugly way to fill up the space
  // If this isn't done, bg has a border
  :deep path {
    transform: scale(1.2) translate(-2px, -2px);
  }

  filter: invert(1) brightness(100);
  .p-outer.selected > & {
    display: flex;
    filter: invert(0);
    background-color: white;
    color: var(--color-primary);
  }
}
.video,
.star-icon,
.livephoto {
  position: absolute;
  z-index: 100;
  pointer-events: none;
  transition: transform 0.15s ease;
  filter: invert(1) brightness(100);
}
.video,
.livephoto {
  position: absolute;
  top: var(--icon-dist);
  right: var(--icon-dist);
  .p-outer.selected > & {
    transform: translate(-$icon-size, $icon-size);
  }

  display: flex;
  align-items: center;
  justify-content: center;

  .time {
    font-size: 0.75em;
    font-weight: bold;
    margin-right: 3px;
  }
}
.livephoto {
  pointer-events: auto;
}
.star-icon {
  bottom: var(--icon-dist);
  left: var(--icon-dist);
  .p-outer.selected > & {
    transform: translate($icon-size, -$icon-size);
  }
}

/* Actual image */
div.img-outer {
  position: relative;
  box-sizing: border-box;
  padding: 0;
  cursor: pointer;

  transition: padding 0.15s ease;
  .p-outer.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  .p-outer.placeholder > & {
    background-color: var(--color-background-dark);
    background-clip: content-box, padding-box;
  }

  > img {
    background-clip: content-box;
    object-fit: cover;
    z-index: 1;
    background-color: var(--color-background-dark);

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;
    pointer-events: none;
    transition: border-radius 0.1s ease-in, transform 0.3s ease-in-out;

    .p-outer.placeholder > & {
      display: none;
    }
    .p-outer.error & {
      object-fit: contain;
    }
  }

  > video {
    pointer-events: none;
    object-fit: cover;
    z-index: 2;
  }

  > .overlay {
    pointer-events: none;
    position: absolute;
    top: 0;
    left: 0;
    z-index: 3;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.2) 0%, transparent 30%);

    display: none;
    transition: border-radius 0.1s ease-in;
    @media (hover: hover) {
      .p-outer:not(.selected):hover > & {
        display: block;
      }
    }
  }

  > * {
    @media (max-width: 768px) {
      .selected > & {
        border-radius: $icon-size;
        border-top-left-radius: 0;
      }
    }
  }
}
</style>
