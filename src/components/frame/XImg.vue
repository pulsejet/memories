<template>
  <img :alt="alt" :src="dataSrc" @load="load" />
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { fetchImage } from "./XImgCache";

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
    src() {
      this.loadImage();
    },
  },

  mounted() {
    this.loadImage();
  },

  beforeUnmount() {
    this.cleanup();
  },

  methods: {
    async loadImage() {
      if (!this.src) return;

      // Clean up previous blob
      this.cleanup();

      // Just set src if not http
      if (this.src.startsWith("data:") || this.src.startsWith("blob:")) {
        this.dataSrc = this.src;
        return;
      }

      // Fetch image with axios
      try {
        this.dataSrc = URL.createObjectURL(await fetchImage(this.src));
      } catch (error) {
        this.dataSrc = BLANK_IMG;
        this.$emit("error", error);
      }
    },

    load() {
      if (this.dataSrc === BLANK_IMG) return;
      this.$emit("load", this.dataSrc);
    },

    cleanup() {
      if (this.dataSrc.startsWith("blob:")) URL.revokeObjectURL(this.dataSrc);
    },
  },
});
</script>
