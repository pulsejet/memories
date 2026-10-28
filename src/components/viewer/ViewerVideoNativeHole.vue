<!-- Fake video implementing VideoController; actually controls the native surface. -->
<template>
  <div class="memories-video-frame" :style="{ width: frameW, height: frameH }" />
</template>

<script setup lang="ts">
import { computed } from 'vue';

import * as nativex from '@native';
import type { IPhoto } from '@typings';

const props = defineProps<{
  srcs?: string[] | null;
  photo?: IPhoto | null;
}>();

// Contain-fit frame, like object-fit. Zoom/pan ride on the stage mirror
// transform; this size is only the at-rest box.
const frameBox = computed((): readonly [number, number] | null => {
  const photo = props.photo;
  const vw = photo?.w && photo?.h ? photo.w : 16;
  const vh = photo?.w && photo?.h ? photo.h : 9;
  const cw = window.innerWidth;
  const ch = window.innerHeight;
  if (!cw || !ch) return null;
  const s = Math.min(cw / vw, ch / vh);
  if (!Number.isFinite(s) || s <= 0) return null;
  return [Math.max(1, Math.round(vw * s)), Math.max(1, Math.round(vh * s))];
});

const frameW = computed(() => (frameBox.value ? `${frameBox.value[0]}px` : undefined));
const frameH = computed(() => (frameBox.value ? `${frameBox.value[1]}px` : undefined));

// Pretend to be a video: play() starts the native hole video.
async function play(): Promise<void> {
  const src = props.srcs?.[0];
  if (!src) throw new Error('ViewerVideoNativeHole: missing src');
  await nativex.viewVideoUrl(src);
}

defineExpose({ play });
</script>

<style lang="scss" scoped>
.memories-video-frame {
  pointer-events: none;
  background: transparent !important;
}
</style>
