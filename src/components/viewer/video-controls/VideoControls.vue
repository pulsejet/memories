<template>
  <div class="m-vc">
    <CenterPlayButton :video="video" />
    <BufferingSpinner :video="video" />

    <div class="m-vc-controls">
      <div class="m-vc-controls-row">
        <PlayButton :video="video" />
        <Volume :video="video" class="m-vc-controls-volume" />
        <TimeTicker :video="video" />
        <div class="m-vc-controls-spacer" />
        <SettingsMenu :video="video" />
        <PipButton :video="video" />
        <FullscreenButton :video="video" />
      </div>

      <Track :video="video" :thumbnails="thumbnails" />
    </div>
  </div>
</template>

<script setup lang="ts">
import BufferingSpinner from './BufferingSpinner.vue';
import CenterPlayButton from './CenterPlayButton.vue';
import PlayButton from './PlayButton.vue';
import FullscreenButton from './FullscreenButton.vue';
import PipButton from './PipButton.vue';
import SettingsMenu from './SettingsMenu.vue';
import TimeTicker from './TimeTicker.vue';
import Track from './Track.vue';
import Volume from './Volume.vue';

defineProps<{
  video?: HTMLVideoElement | null;
  thumbnails?: string | null;
}>();
</script>

<style lang="scss" scoped>
.m-vc {
  position: absolute;
  inset: 0;
  z-index: 6;
  pointer-events: none;
}

.m-vc-controls {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;

  margin-inline: 24px;
  padding-block-end: calc(50px + env(safe-area-inset-bottom, 0px)) !important;
  @media (max-width: 768px) {
    // Above the mobile bottom bar (~66px) with a small gap.
    padding-block-end: calc(74px + env(safe-area-inset-bottom, 0px)) !important;
  }

  pointer-events: auto;
  opacity: 0;
  visibility: hidden;
  transition:
    opacity 0.2s ease-in-out,
    visibility 0.2s allow-discrete;

  &-row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: auto;
    margin: 0 -9px 0 -12px;
  }

  &-spacer {
    flex: 1 1 auto;
  }

  &-volume {
    @media (max-width: 768px) {
      display: none;
    }
  }

  :deep(.button-vue) {
    background-color: transparent !important;
    color: #fff !important;
  }
}
</style>
