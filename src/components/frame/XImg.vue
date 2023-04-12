<template>
  <img :alt="alt" :src="dataSrc" @load="load" decoding="async" />
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { fetchImage, sticky } from "./XImgCache";

const BLANK_IMG =
  "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

export default defineComponent({
  name: "XImg",
  props: {
    src: {
      type: String,
      required: false,
    },
    alt: {
      type: String,
      default: "",
    },
  },

  data: () => {
    return {
      dataSrc: BLANK_IMG,
      _blobLocked: false,
      _state: 0,
    };
  },

  watch: {
    src(newSrc, oldSrc) {
      this.loadImage();
    },
  },

  mounted() {
    this.loadImage();
  },

  beforeDestroy() {
    this._state = -1;

    // Free up the blob if it was locked
    this.freeBlob();
  },

  methods: {
    async loadImage() {
      if (!this.src) return;

      // Free up current blob if it was locked
      this.freeBlob();

      // Just set src if not http
      if (this.src.startsWith("data:") || this.src.startsWith("blob:")) {
        this.dataSrc = this.src;
        return;
      }

      // Fetch image with worker
      try {
        const state = (this._state = Math.random());
        const blobSrc = await fetchImage(this.src);
        if (state !== this._state) return; // aborted
        this.dataSrc = blobSrc;

        // Locking is needed primary for thumbnails,
        // since photoswipe uses the thumb url for the animated zoom-in
        this.lockBlob();
      } catch (error) {
        this.dataSrc = BLANK_IMG;
        this.$emit("error", error);
        console.error("Failed to load XImg", error);
      }
    },

    load() {
      if (this.dataSrc === BLANK_IMG) return;
      this.$emit("load", this.dataSrc);
    },

    lockBlob() {
      sticky(this.dataSrc, 1);
      this._blobLocked = true;
    },

    freeBlob() {
      if (!this._blobLocked) return;
      sticky(this.dataSrc, -1);
      this._blobLocked = false;
    },
  },
});
</script>
