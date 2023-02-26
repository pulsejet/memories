<template>
  <img :alt="alt" :src="dataSrc" @load="load" />
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { fetchImage } from "./XImgCache";

const BLOB_CACHE: { [src: string]: string } = {};
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
        const src = this.src;
        if (BLOB_CACHE[src]) {
          this.dataSrc = BLOB_CACHE[src];
          return;
        }

        const newBlob = await fetchImage(src);
        if (this.src === src) {
          const blobUrl = URL.createObjectURL(newBlob);
          BLOB_CACHE[src] = this.dataSrc = blobUrl;
          setTimeout(() => {
            if (BLOB_CACHE[src] === blobUrl) delete BLOB_CACHE[src];
            URL.revokeObjectURL(blobUrl);
          }, 60 * 1000);
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
  },
});
</script>
