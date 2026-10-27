<template>
  <div v-if="activeThumb" class="m-vc-track-preview" aria-hidden="true" :style="previewStyle" />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import * as vutils from './utils';
import * as thumbUtils from './thumbnails';

// Hover position and track geometry from the seek bar.
const props = defineProps<{
  thumbnails?: string | null;
  time: number;
  ratio: number;
  trackWidth: number;
  visible: boolean;
}>();

// Parsed storyboard cues plus the in-flight load, aborted on URL change.
const thumbCues = ref<thumbUtils.ThumbnailCue[]>([]);
let thumbAbort: AbortController | null = null;

// Storyboard cue under the hover time, if any.
const activeThumb = computed(() => {
  if (!props.visible || thumbCues.value.length === 0) return undefined;
  return thumbUtils.findThumbnailCue(thumbCues.value, props.time);
});

// Sprite-cropped (or cover-fit) preview, clamped inside the track edges.
const previewStyle = computed(() => {
  const thumb = activeThumb.value;
  if (!thumb) return {};

  const pct = vutils.clamp01(props.ratio) * 100;
  const half = props.trackWidth > 0 ? ((160 / 2 + 8) / props.trackWidth) * 100 : 0;
  const left = `${Math.min(Math.max(pct, half), 100 - half)}%`;

  // Sprite tile: offset into the shared sheet.
  if (thumb.x !== undefined && thumb.y !== undefined && thumb.w !== undefined && thumb.h !== undefined) {
    return {
      backgroundImage: `url("${thumb.url}")`,
      backgroundPosition: `-${thumb.x}px -${thumb.y}px`,
      width: `${thumb.w}px`,
      height: `${thumb.h}px`,
      left,
    };
  }

  // Standalone image: fixed frame, cover fit.
  return {
    backgroundImage: `url("${thumb.url}")`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
    width: '160px',
    height: '90px',
    left,
  };
});

// Let the browser parse the storyboard VTT into cues.
watch(
  () => props.thumbnails,
  async (url) => {
    // Start clean: abort the previous load when the URL changes.
    abortThumbLoad();
    thumbCues.value = [];

    // Nothing to load without a storyboard URL.
    if (!url) return;

    // Parsed cues land here; the guard drops stale loads.
    const ctrl = new AbortController();
    thumbAbort = ctrl;
    const cues = await thumbUtils.loadThumbnailCues(url, ctrl.signal);
    if (!ctrl.signal.aborted) {
      thumbCues.value = cues;
    }
  },
  { immediate: true },
);

// Abort any in-flight fetch.
function abortThumbLoad() {
  thumbAbort?.abort();
  thumbAbort = null;
}

onBeforeUnmount(abortThumbLoad);
</script>

<style lang="scss" scoped>
.m-vc-track-preview {
  position: absolute;
  bottom: calc(100% + 42px);
  border: 1px solid rgba(255, 255, 255, 0.8);
  border-radius: 4px;
  background-color: #000;
  background-repeat: no-repeat;
  transform: translateX(-50%);
  pointer-events: none;
}
</style>
