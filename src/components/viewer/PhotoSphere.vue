<template>
  <div id="viewer" class="viewer__photosphere top-left fill-block">
    <NcButton
      id="close-photosphere-viewer"
      :ariaLabel="t('memories', 'Close')"
      :title="t('memories', 'Close')"
      type="tertiary"
      @click="close"
    >
      <CloseThickIcon :size="20" />
    </NcButton>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import { API } from '@services/API';
import type { IPhoto } from '@typings';
import * as utils from '@services/utils';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import CloseThickIcon from 'vue-material-design-icons/CloseThick.vue';

import { Viewer } from '@photo-sphere-viewer/core';
import { AutorotatePlugin } from '@photo-sphere-viewer/autorotate-plugin';
import '@photo-sphere-viewer/core/index.css';

export default defineComponent({
  props: {
    photo: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
  },

  components: {
    NcButton,
    CloseThickIcon,
  },

  emits: {
    close: () => true,
  },

  data: () => ({
    viewer: null as Viewer | null,
  }),

  async mounted() {
    // Create the photosphere viewer
    console.assert(document.getElementById('viewer'), 'PhotoSphere container not found');
    this.viewer = new Viewer({
      container: 'viewer',
      panorama: API.IMAGE_DECODABLE(this.photo.fileid, this.photo.etag),
      caption: this.exifTitle() + this.exifDate(),
      description: this.exifDesc(),
      navbar: ['autorotate', 'zoom', 'move', 'description', 'caption', 'fullscreen'],
      plugins: [
        [
          AutorotatePlugin,
          {
            autorotatePitch: '5deg',
            autostartOnIdle: false,
            autostartDelay: null,
          },
        ],
      ],
    });

    // Handle keyboard
    window.addEventListener('keydown', this.handleKeydown, true);
  },

  beforeDestroy() {
    this.close();
  },

  methods: {
    exifTitle(): string {
      const title = this.photo?.imageInfo?.exif?.Title;
      if (title) return '<b>' + title + '</b> — ';
      return '';
    },

    exifDesc(): string | undefined {
      const desc = this.photo?.imageInfo?.exif?.Description;
      return desc;
    },

    exifDate(): string {
      const date = this.photo?.imageInfo?.datetaken;
      if (!date) return '';
      return utils.getLongDateStr(new Date(date * 1000), false, true);
    },

    close() {
      this.viewer?.destroy();
      window.removeEventListener('keydown', this.handleKeydown, true);
      this.$emit('close');
    },

    handleKeydown(event: KeyboardEvent) {
      event.stopImmediatePropagation();

      if (event.key === 'Escape') {
        event.preventDefault();
        this.close();
      }
    },
  },
});
</script>

<style lang="scss">
// Take full screen size
.viewer__photosphere {
  z-index: 10100;
  background-color: black;

  box-sizing: content-box;
  .psv-container,
  .psv-container * {
    box-sizing: content-box !important;
  }

  // Overlay top-right close button
  #close-photosphere-viewer {
    position: absolute;
    right: 0;
    top: 0;
    z-index: 9999;
    //background-color: transparent;
    margin-right: 10px;
  }
}
</style>
