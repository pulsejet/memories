<template>
  <div v-if="isBuffering" class="m-vc-buffering" aria-hidden="true">
    <div class="m-vc-spinner" />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useEventListener } from '@vueuse/core';

import type { VideoController } from '@typings';

const props = defineProps<{
  video?: VideoController | null;
}>();

const isBuffering = ref(true);

useEventListener(
  () => props.video,
  ['waiting', 'seeking', 'emptied'],
  () => {
    isBuffering.value = true;
  },
);

useEventListener(
  () => props.video,
  ['play', 'playing', 'pause', 'ended', 'canplay', 'error'],
  () => {
    isBuffering.value = false;
  },
);

useEventListener(
  () => props.video,
  'seeked',
  () => {
    // Seek done; still buffering until enough data (waiting re-fires if not).
    isBuffering.value = (props.video?.readyState ?? 0) < 3;
  },
);

onMounted(() => {
  const v = props.video;
  if (!v) return;

  // Preload none + untouched: nothing is loading, no spinner.
  const untouched = v.preload === 'none' && v.paused && v.readyState === 0 && !v.autoplay;
  isBuffering.value = v.readyState < 3 && !untouched;
});
</script>

<style lang="scss" scoped>
.m-vc-buffering {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
  animation: m-vc-fade-in 100ms ease-in 300ms both;
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

@keyframes m-vc-fade-in {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes m-vc-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
