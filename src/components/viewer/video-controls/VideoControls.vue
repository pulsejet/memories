<template>
  <div class="m-vc-controls">
    <Track :video="video" />

    <div class="m-vc-controls-row">
      <PlayButton :video="video" />
      <Volume :video="video" />
      <TimeTicker :video="video" />
      <FullscreenButton :video="video" />
    </div>
  </div>
</template>

<script setup lang="ts">
import PlayButton from './PlayButton.vue';
import FullscreenButton from './FullscreenButton.vue';
import TimeTicker from './TimeTicker.vue';
import Track from './Track.vue';
import Volume from './Volume.vue';

defineProps<{
  video?: HTMLVideoElement | null;
}>();
</script>

<style lang="scss" scoped>
:deep(.button-vue) {
  background-color: transparent !important;
}
</style>
