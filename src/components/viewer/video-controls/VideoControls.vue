<template>
  <div class="m-vc-controls">
    <Track :video="video" />

    <div class="m-vc-controls-row">
      <PlayButton :video="video" />
      <Volume :video="video" />
      <TimeTicker :video="video" />
    </div>
  </div>
</template>

<script setup lang="ts">
import PlayButton from './PlayButton.vue';
import TimeTicker from './TimeTicker.vue';
import Track from './Track.vue';
import Volume from './Volume.vue';

defineProps<{
  video?: HTMLVideoElement | null;
}>();
</script>
