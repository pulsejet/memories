<template>
  <div class="m-vc-controls">
    <PlayButton :video="video" />
    <Volume :video="video" />
    <TimeTicker :video="video" />
  </div>
</template>

<script setup lang="ts">
import PlayButton from './PlayButton.vue';
import TimeTicker from './TimeTicker.vue';
import Volume from './Volume.vue';

defineProps<{
  video?: HTMLVideoElement | null;
}>();
</script>
