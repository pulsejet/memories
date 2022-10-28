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
    <Check
      :size="15"
      class="select"
      v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
      @click="toggleSelect"
    />

    <Video :size="20" v-if="data.flag & c.FLAG_IS_VIDEO" />
    <Star :size="20" v-if="data.flag & c.FLAG_IS_FAVORITE" />

    <div
      class="img-outer fill-block"
      @click="emitClick"
      @contextmenu="contextmenu"
      @touchstart="touchstart"
      @touchmove="touchend"
      @touchend="touchend"
      @touchcancel="touchend"
    >
      <img
        ref="img"
        class="fill-block"
        :src="src"
        :key="data.fileid"
        @load="load"
        @error="error"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { Component, Prop, Emit, Mixins, Watch } from "vue-property-decorator";
import { IDay, IPhoto } from "../../types";

import { getPhotosPreviewUrl, getPreviewUrl } from "../../services/FileUtils";
import errorsvg from "../../assets/error.svg";
import GlobalMixin from "../../mixins/GlobalMixin";

import Check from "vue-material-design-icons/Check.vue";
import Video from "vue-material-design-icons/Video.vue";
import Star from "vue-material-design-icons/Star.vue";

@Component({
  components: {
    Check,
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
  @Emit("click") emitClick() {}

  @Watch("data")
  onDataChange(newData: IPhoto, oldData: IPhoto) {
    // Copy flags relevant to this component
    if (oldData && newData) {
      newData.flag |=
        oldData.flag & (this.c.FLAG_SELECTED | this.c.FLAG_LOAD_FAIL);
    }
  }

  mounted() {
    this.hasFaceRect = false;
    this.refresh();
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

    const fun =
      this.$route.name === "albums" ? getPhotosPreviewUrl : getPreviewUrl;
    return fun(this.data.fileid, this.data.etag, false, size);
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

  touchstart() {
    this.touchTimer = window.setTimeout(() => {
      this.toggleSelect();
      this.touchTimer = 0;
    }, 600);
  }

  contextmenu(e: Event) {
    e.preventDefault();
    e.stopPropagation();
  }

  touchend() {
    if (this.touchTimer) {
      clearTimeout(this.touchTimer);
      this.touchTimer = 0;
    }
  }
}
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
  &.leaving {
    transition: all 0.2s ease-in;
    transform: scale(0.9);
    opacity: 0;
  }
}

// Distance of icon from border
$icon-dist: min(10px, 6%);

/* Extra icons */
.check-icon.select {
  position: absolute;
  top: $icon-dist;
  left: $icon-dist;
  z-index: 100;
  background-color: var(--color-main-background);
  border-radius: 50%;
  cursor: pointer;
  display: none;

  .p-outer:hover > & {
    display: flex;
  }
  .selected > & {
    display: flex;
    filter: invert(1);
  }
}
.video-icon,
.star-icon {
  position: absolute;
  z-index: 100;
  pointer-events: none;
  filter: invert(1) brightness(100);
}
.video-icon {
  top: $icon-dist;
  right: $icon-dist;
}
.star-icon {
  bottom: $icon-dist;
  left: $icon-dist;
}

/* Actual image */
div.img-outer {
  padding: 2px;
  box-sizing: border-box;
  @media (max-width: 768px) {
    padding: 1px;
  }

  transition: padding 0.1s ease;
  background-clip: content-box, padding-box;
  background-color: var(--color-background-dark);

  .selected > & {
    padding: calc($icon-dist - 2px);
  }

  > img {
    filter: contrast(1.05); // most real world images are a bit overexposed
    background-clip: content-box;
    object-fit: cover;
    cursor: pointer;

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;
    transition: box-shadow 0.1s ease;

    .selected > & {
      box-shadow: 0 0 4px 2px var(--color-primary);
    }
    .p-outer.placeholder > & {
      display: none;
    }
    .p-outer.error & {
      object-fit: contain;
    }
  }
}
</style>