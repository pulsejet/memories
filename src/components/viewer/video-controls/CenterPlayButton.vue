<template>
  <div
    v-if="!isPlaying"
    class="m-vc-center"
    role="button"
    tabindex="0"
    :aria-label="t('memories', 'Play')"
    @click="playVideo"
    @keydown.enter.prevent="playVideo"
    @keydown.space.prevent="playVideo"
  >
    <PlayIcon :size="48" />
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import PlayIcon from 'vue-material-design-icons/Play.vue';

import { t } from '@services/l10n';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const isPlaying = ref(false);

function onPlay() {
  isPlaying.value = true;
}

function onPlaying() {
  isPlaying.value = true;
}

function onPause() {
  isPlaying.value = false;
}

function onEnded() {
  isPlaying.value = false;
}

async function playVideo() {
  try {
    await props.video?.play();
  } catch {
    // Ignore - user can retry from the bottom bar
  }
}

function attachVideo() {
  if (!props.video) return;
  isPlaying.value = !props.video.paused;
  props.video.addEventListener('play', onPlay);
  props.video.addEventListener('playing', onPlaying);
  props.video.addEventListener('pause', onPause);
  props.video.addEventListener('ended', onEnded);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('play', onPlay);
  el?.removeEventListener('playing', onPlaying);
  el?.removeEventListener('pause', onPause);
  el?.removeEventListener('ended', onEnded);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>

<style lang="scss" scoped>
.m-vc-center {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  cursor: pointer;
  pointer-events: auto;
  transition:
    opacity 0.2s ease-in-out,
    visibility 0.2s allow-discrete;

  > * {
    pointer-events: none;
  }

  &:hover,
  &:active {
    background: rgba(0, 0, 0, 0.7);
  }

  &:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 4px;
  }
}
</style>
