<template>
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
</template>

<script setup lang="ts">
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
.m-vc-controls {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;

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
  }
}
</style>
