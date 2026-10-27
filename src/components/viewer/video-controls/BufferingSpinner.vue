<template>
  <div v-if="isBuffering" class="m-vc-buffering" aria-hidden="true">
    <div class="m-vc-spinner" />
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const isBuffering = ref(true);

function onPlay() {
  isBuffering.value = false;
}

function onPlaying() {
  isBuffering.value = false;
}

function onPause() {
  isBuffering.value = false;
}

function onEnded() {
  isBuffering.value = false;
}

function onWaiting() {
  isBuffering.value = true;
}

function onSeeking() {
  isBuffering.value = true;
}

function onSeeked() {
  // Seek done; still buffering until enough data (waiting re-fires if not).
  isBuffering.value = (props.video?.readyState ?? 0) < 3;
}

function onCanPlay() {
  isBuffering.value = false;
}

function onEmptied() {
  isBuffering.value = true;
}

function attachVideo() {
  if (!props.video) return;
  const v = props.video;
  // Preload none + untouched: nothing is loading, no spinner.
  isBuffering.value = v.readyState < 3 && !(v.preload === 'none' && v.paused && v.readyState === 0 && !v.autoplay);
  v.addEventListener('play', onPlay);
  v.addEventListener('playing', onPlaying);
  v.addEventListener('pause', onPause);
  v.addEventListener('ended', onEnded);
  v.addEventListener('waiting', onWaiting);
  v.addEventListener('seeking', onSeeking);
  v.addEventListener('seeked', onSeeked);
  v.addEventListener('canplay', onCanPlay);
  v.addEventListener('emptied', onEmptied);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('play', onPlay);
  el?.removeEventListener('playing', onPlaying);
  el?.removeEventListener('pause', onPause);
  el?.removeEventListener('ended', onEnded);
  el?.removeEventListener('waiting', onWaiting);
  el?.removeEventListener('seeking', onSeeking);
  el?.removeEventListener('seeked', onSeeked);
  el?.removeEventListener('canplay', onCanPlay);
  el?.removeEventListener('emptied', onEmptied);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>

<style lang="scss" scoped>
.m-vc-buffering {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.m-vc-spinner {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  border: 6px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  animation: m-vc-spin 1s linear infinite;
  @media (prefers-reduced-motion) {
    animation-duration: 8s;
  }
}

@keyframes m-vc-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
