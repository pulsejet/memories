<template>
  <div
    class="p-outer fill-block"
    :class="{
      selected: data.flag & c.FLAG_SELECTED,
      placeholder: data.flag & c.FLAG_PLACEHOLDER,
      leaving: data.flag & c.FLAG_LEAVING,
      error: data.flag & c.FLAG_LOAD_FAIL,
    }"
  >
    <CheckCircle
      :size="18"
      class="select"
      v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
      @click="toggleSelect"
    />

    <div class="video" v-if="data.flag & c.FLAG_IS_VIDEO">
      <span v-if="data.video_duration" class="time">
        {{ videoDuration }}
      </span>
      <Video :size="22" />
    </div>

    <Star :size="22" v-if="data.flag & c.FLAG_IS_FAVORITE" />

    <div
      class="img-outer fill-block"
      @contextmenu="contextmenu"
      @pointerdown="$emit('pointerdown', $event)"
      @touchstart.passive="$emit('touchstart', $event)"
      @touchmove="$emit('touchmove', $event)"
      @touchend.passive="$emit('touchend', $event)"
      @touchcancel.passive="$emit('touchend', $event)"
    >
      <img
        ref="img"
        :class="['fill-block', `memories-thumb-${data.key}`]"
        draggable="false"
        :src="src"
        :key="data.fileid"
        @load="load"
        @error="error"
      />
      <div class="overlay" />
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop, Watch } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";

import { getPreviewUrl } from "../../services/FileUtils";
import { IDay, IPhoto } from "../../types";
import * as utils from "../../services/Utils";

import errorsvg from "../../assets/error.svg";
import CheckCircle from "vue-material-design-icons/CheckCircle.vue";
import Star from "vue-material-design-icons/Star.vue";
import Video from "vue-material-design-icons/PlayCircleOutline.vue";

@Component({
  components: {
    CheckCircle,
    Video,
    Star,
  },
})
export default class Photo extends Mixins(GlobalMixin) {
  private touchTimer = 0;
  private src = null;
  private hasFaceRect = false;

  @Prop() data: IPhoto;
  @Prop() day: IDay;

  @Emit("select") emitSelect(data: IPhoto) {}

  @Watch("data")
  onDataChange(newData: IPhoto, oldData: IPhoto) {
    // Copy flags relevant to this component
    if (oldData && newData) {
      newData.flag |=
        oldData.flag & (this.c.FLAG_SELECTED | this.c.FLAG_LOAD_FAIL);
    }
  }

  @Watch("data.etag")
  onEtagChange() {
    this.hasFaceRect = false;
    this.refresh();
  }

  mounted() {
    this.hasFaceRect = false;
    this.refresh();
  }

  get videoDuration() {
    if (this.data.video_duration) {
      return utils.getDurationStr(this.data.video_duration);
    }
    return null;
  }

  async refresh() {
    this.src = await this.getSrc();
  }

  /** Get src for image to show */
  async getSrc() {
    if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
      return null;
    } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
      return errorsvg;
    } else {
      return this.url();
    }
  }

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

    return getPreviewUrl(this.data, false, size);
  }

  /** Set src with overlay face rect */
  async addFaceRect() {
    if (!this.data.facerect || this.hasFaceRect) return;
    this.hasFaceRect = true;

    const canvas = document.createElement("canvas");
    const context = canvas.getContext("2d");
    const img = this.$refs.img as HTMLImageElement;

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
        this.src = URL.createObjectURL(blob);
      },
      "image/jpeg",
      0.95
    );
  }

  /** Post load tasks */
  load() {
    this.addFaceRect();
  }

  /** Error in loading image */
  error(e: any) {
    this.data.flag |= this.c.FLAG_LOAD_FAIL;
    this.refresh();
  }

  /** Clear timers */
  beforeUnmount() {
    clearTimeout(this.touchTimer);
  }

  toggleSelect() {
    if (this.data.flag & this.c.FLAG_PLACEHOLDER) return;
    this.emitSelect(this.data);
  }

  contextmenu(e: Event) {
    // user is trying to select the photo
    if (document.body.classList.contains("vue-touching")) {
      e.preventDefault();
      e.stopPropagation();
    }
  }
}
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
  body:not(.vue-touching) .p-outer:hover > & {
    display: flex;
  }

  opacity: 0.7;
  &:hover,
  .p-outer.selected & {
    opacity: 1;
  }

  // Extremely ugly way to fill up the space
  // If this isn't done, bg has a border
  :deep path {
    transform: scale(1.19) translate(-1.85px, -1.85px);
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
.star-icon {
  position: absolute;
  z-index: 100;
  pointer-events: none;
  transition: transform 0.15s ease;
  filter: invert(1) brightness(100);
}
.video {
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
.star-icon {
  bottom: var(--icon-dist);
  left: var(--icon-dist);
  .p-outer.selected > & {
    transform: translate($icon-size, -$icon-size);
  }
}

/* Actual image */
div.img-outer {
  box-sizing: border-box;
  padding: 0;

  transition: padding 0.15s ease;
  .p-outer.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  .p-outer.placeholder > & {
    background-color: var(--color-background-dark);
    background-clip: content-box, padding-box;
  }

  > img {
    filter: contrast(1.05); // most real world images are a bit overexposed
    background-clip: content-box;
    object-fit: cover;
    cursor: pointer;
    background-color: var(--color-background-dark);

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;
    transition: border-radius 0.1s ease-in;

    .p-outer.placeholder > & {
      display: none;
    }
    .p-outer.error & {
      object-fit: contain;
    }
  }

  & > .overlay {
    pointer-events: none;
    width: 100%;
    height: 100%;
    transform: translateY(-100%); // very weird stuff
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.2) 0%, transparent 30%);

    display: none;
    transition: border-radius 0.1s ease-in;
    body:not(.vue-touching) .p-outer:not(.selected):hover > & {
      display: block;
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