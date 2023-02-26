<template>
  <img :alt="alt" :src="dataSrc" @load="load" />
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { fetchImage } from "./XImgCache";

const BLOB_CACHE: { [src: string]: [number, string] } = {};
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
    };
  },

  watch: {
    src(newSrc, oldSrc) {
      this.cleanup(oldSrc);
      this.loadImage();
    },
  },

  mounted() {
    this.loadImage();
  },

  beforeDestroy() {
    this.cleanup(this.src);
  },

  methods: {
    async loadImage() {
      if (!this.src) return;

      // Just set src if not http
      if (this.src.startsWith("data:") || this.src.startsWith("blob:")) {
        this.dataSrc = this.src;
        return;
      }

      // Fetch image with axios
      try {
        if (BLOB_CACHE[this.src]) {
          this.dataSrc = BLOB_CACHE[this.src][1];
          BLOB_CACHE[this.src][0]++;
        } else {
          this.dataSrc = URL.createObjectURL(await fetchImage(this.src));
          BLOB_CACHE[this.src] = [1, this.dataSrc];
        }
      } catch (error) {
        this.dataSrc = BLANK_IMG;
        this.$emit("error", error);
      }
    },

    load() {
      if (this.dataSrc === BLANK_IMG) return;
      this.$emit("load", this.dataSrc);
    },

    async cleanup(src: string) {
      if (!src) return;

      // Wait for 1s before collecting garbage
      await new Promise((r) => setTimeout(r, 1000));

      // Clean up blob cache
      const cache = BLOB_CACHE[src];
      if (!cache) return;

      // Remove blob from cache
      if (--cache[0] <= 0) {
        URL.revokeObjectURL(cache[1]);
        delete BLOB_CACHE[src];
      }
    },
  },
});
</script>
