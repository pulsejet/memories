<template>
  <!-- Directly use SVG element if possible -->
  <div class="svg" v-if="svg" v-html="svg"></div>

  <!-- Otherwise use img element -->
  <img v-else :alt="alt" :src="dataSrc" @load="load" />
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { fetchImage, sticky } from './XImgCache';

const BLANK_IMG = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

export default defineComponent({
  name: 'XImg',
  props: {
    src: {
      type: String,
      required: false,
    },
    alt: {
      type: String,
      default: '',
    },
    svgTag: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    load: (src: string) => true,
    error: (error: Error) => true,
  },

  data: () => ({
    dataSrc: BLANK_IMG,
    _blobLocked: false,
    _state: 0,
  }),

  watch: {
    src() {
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

  computed: {
    svg() {
      if (this.svgTag && this.dataSrc.startsWith('data:image/svg+xml')) {
        return window.atob(this.dataSrc.split(',')[1]);
      }
      return null;
    },
  },

  methods: {
    async loadImage() {
      if (!this.src) return;

      // Free up current blob if it was locked
      this.freeBlob();

      // Just set src if not http
      if (this.src.startsWith('data:') || this.src.startsWith('blob:')) {
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
        this.$emit('error', error);
        console.error('Failed to load XImg', error);
      }
    },

    load() {
      if (this.dataSrc === BLANK_IMG) return;
      this.$emit('load', this.dataSrc);
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

<style lang="scss" scoped>
div.svg > :deep svg {
  width: 100%;
  height: 100%;
}
</style>
