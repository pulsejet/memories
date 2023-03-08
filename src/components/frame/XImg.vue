<template>
  <img :alt="alt" :src="dataSrc" @load="load" />
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { fetchImage } from "./XImgCache";

const BLANK_IMG =
  "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
const BLOB_CACHE: { [src: string]: [number, string] } = {};

// Periodic blob cache cleaner
window.setInterval(() => {
  for (const src in BLOB_CACHE) {
    const cache = BLOB_CACHE[src];
    if (cache[0] <= 0) {
      URL.revokeObjectURL(cache[1]);
      delete BLOB_CACHE[src];
    }
  }
}, 10000);

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
      isDestroyed: false,
    };
  },

  watch: {
    src(newSrc, oldSrc) {
      this.free(oldSrc);
      this.loadImage();
    },
  },

  mounted() {
    this.loadImage();
  },

  beforeDestroy() {
    this.free(this.src);
    this.isDestroyed = true;
  },

  methods: {
    async loadImage() {
      if (!this.src) return;
      this.isDestroyed = false;

      // Just set src if not http
      if (this.src.startsWith("data:") || this.src.startsWith("blob:")) {
        this.dataSrc = this.src;
        return;
      }

      // Fetch image with axios
      try {
        // Use BLOB from cache assuming it exists
        const usedCache = (src: string) => {
          if (BLOB_CACHE[src]) {
            this.dataSrc = BLOB_CACHE[src][1];
            BLOB_CACHE[src][0]++;
            return true;
          }
          return false;
        };

        // Check if the blob cache exists
        if (!usedCache(this.src)) {
          const src = this.src;
          const img = await fetchImage(src);
          if (this.src !== src || this.isDestroyed) {
            // the src has changed, abort
            return;
          }

          // Check if the blob cache exists now
          // In this case, someone else already created the blob
          // Free up the current blob and use the existing one instead
          if (!usedCache(src)) {
            // Create new blob cache entry
            this.dataSrc = URL.createObjectURL(img);
            BLOB_CACHE[src] = [1, this.dataSrc];
          }
        }
      } catch (error) {
        this.dataSrc = BLANK_IMG;
        this.$emit("error", error);
        console.error(error);
      }
    },

    load() {
      if (this.dataSrc === BLANK_IMG) return;
      this.$emit("load", this.dataSrc);
    },

    /** Mark a blob cache as less used */
    async free(src: string) {
      const cache = BLOB_CACHE[src];
      if (!cache) return;
      --cache[0];
    },
  },
});
</script>
