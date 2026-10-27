<template>
  <div
    ref="trackEl"
    class="m-vc-track"
    role="slider"
    tabindex="0"
    :aria-label="t('memories', 'Seek')"
    :aria-valuemin="0"
    :aria-valuemax="Math.round(duration)"
    :aria-valuenow="Math.round(displayTime)"
    :aria-valuetext="`${formatTime(displayTime)} / ${formatTime(duration)}`"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointercancel="onPointerCancel"
    @pointerenter="onPointerEnter"
    @pointerleave="onPointerLeave"
    @keydown="onKeyDown"
  >
    <div class="m-vc-track-bar">
      <div
        v-for="(range, i) in bufferedRanges"
        :key="i"
        class="m-vc-track-buffered"
        :style="{ left: `${range.left}%`, width: `${range.width}%` }"
      />
      <div class="m-vc-track-played" :style="{ width: `${playedPct}%` }" />
      <div class="m-vc-track-thumb" :style="{ left: `${playedPct}%` }" />
      <TrackThumbnail
        :video="video"
        :thumbnails="thumbnails"
        :time="tooltipRatio * clampedDuration"
        :ratio="tooltipRatio"
        :track-width="trackWidth"
        :visible="tooltipVisible"
      />
      <div v-show="tooltipVisible" class="m-vc-track-tooltip" aria-hidden="true" :style="{ left: `${tooltipPct}%` }">
        {{ tooltipText }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { t } from '@services/l10n';
import TrackThumbnail from './TrackThumbnail.vue';
import * as vutils from './utils';

const props = defineProps<{
  video?: HTMLVideoElement | null;
  thumbnails?: string | null;
}>();

const trackEl = ref<HTMLDivElement | null>(null);
const currentTime = ref(0);
const duration = ref(0);
const buffered = ref<{ start: number; end: number }[]>([]);
const scrubbing = ref(false);
const scrubRatio = ref(0);
const hovering = ref(false);
const hoverRatio = ref(0);
const trackWidth = ref(0);

const clampedDuration = computed(() => (Number.isFinite(duration.value) && duration.value > 0 ? duration.value : 0));

const playedRatio = computed(() => {
  if (clampedDuration.value <= 0) return 0;
  if (scrubbing.value) return vutils.clamp01(scrubRatio.value);
  return vutils.clamp01(currentTime.value / clampedDuration.value);
});

const playedPct = computed(() => playedRatio.value * 100);
const displayTime = computed(() => playedRatio.value * clampedDuration.value);
const showHours = computed(() => clampedDuration.value >= 3600);
const tooltipVisible = computed(() => (hovering.value || scrubbing.value) && clampedDuration.value > 0);
const tooltipRatio = computed(() =>
  scrubbing.value ? vutils.clamp01(scrubRatio.value) : vutils.clamp01(hoverRatio.value),
);
const tooltipPct = computed(() => tooltipRatio.value * 100);
const tooltipText = computed(() => vutils.formatMediaTime(tooltipRatio.value * clampedDuration.value, showHours.value));

const bufferedRanges = computed(() => {
  if (clampedDuration.value <= 0) return [];
  return buffered.value
    .map(({ start, end }) => ({
      left: vutils.clamp01(start / clampedDuration.value) * 100,
      width: vutils.clamp01(end / clampedDuration.value) * 100 - vutils.clamp01(start / clampedDuration.value) * 100,
    }))
    .filter((r) => r.width > 0);
});

function formatTime(sec: number) {
  return vutils.formatMediaTime(sec, showHours.value);
}

function ratioFromEvent(e: PointerEvent) {
  const el = trackEl.value;
  if (!el) return 0;
  const rect = el.getBoundingClientRect();
  if (rect.width <= 0) return 0;
  trackWidth.value = rect.width;
  return vutils.clamp01((e.clientX - rect.left) / rect.width);
}

function onPointerDown(e: PointerEvent) {
  if (!props.video || clampedDuration.value <= 0) return;
  scrubbing.value = true;
  scrubRatio.value = ratioFromEvent(e);
  hoverRatio.value = scrubRatio.value;
  hovering.value = true;
  trackEl.value?.setPointerCapture?.(e.pointerId);
}

function onPointerMove(e: PointerEvent) {
  if (clampedDuration.value <= 0) return;
  hoverRatio.value = ratioFromEvent(e);
  if (!scrubbing.value) return;
  scrubRatio.value = hoverRatio.value;
}

function commitScrub() {
  if (!props.video || !scrubbing.value) return;
  const target = vutils.clamp01(scrubRatio.value) * clampedDuration.value;
  scrubbing.value = false;
  currentTime.value = target;
  if (clampedDuration.value > 0) {
    props.video.currentTime = target;
  }
}

function onPointerUp() {
  commitScrub();
}

function onPointerCancel() {
  scrubbing.value = false;
}

function onPointerEnter(e: PointerEvent) {
  if (clampedDuration.value <= 0) return;
  hovering.value = true;
  hoverRatio.value = ratioFromEvent(e);
}

function onPointerLeave() {
  hovering.value = false;
}

function onKeyDown(e: KeyboardEvent) {
  if (!props.video || clampedDuration.value <= 0) return;
  const step = e.shiftKey ? 10 : 5;
  if (e.key === 'ArrowRight') {
    props.video.currentTime = Math.min(clampedDuration.value, props.video.currentTime + step);
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'ArrowLeft') {
    props.video.currentTime = Math.max(0, props.video.currentTime - step);
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'Home') {
    props.video.currentTime = 0;
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'End') {
    props.video.currentTime = clampedDuration.value;
    e.preventDefault();
    e.stopPropagation();
  }
}

function onVideoTimeUpdate() {
  if (props.video) {
    currentTime.value = props.video.currentTime;
  }
}

function onVideoDurationChange() {
  if (props.video && Number.isFinite(props.video.duration)) {
    duration.value = props.video.duration;
  }
}

function onVideoProgress() {
  if (!props.video) return;
  const ranges: { start: number; end: number }[] = [];
  try {
    for (let i = 0; i < props.video.buffered.length; i++) {
      ranges.push({ start: props.video.buffered.start(i), end: props.video.buffered.end(i) });
    }
  } catch {
    return;
  }
  buffered.value = ranges;
}

function attachVideo() {
  if (!props.video) return;
  currentTime.value = props.video.currentTime || 0;
  if (Number.isFinite(props.video.duration)) {
    duration.value = props.video.duration;
  }
  onVideoProgress();
  props.video.addEventListener('timeupdate', onVideoTimeUpdate);
  props.video.addEventListener('durationchange', onVideoDurationChange);
  props.video.addEventListener('loadedmetadata', onVideoDurationChange);
  props.video.addEventListener('progress', onVideoProgress);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('timeupdate', onVideoTimeUpdate);
  el?.removeEventListener('durationchange', onVideoDurationChange);
  el?.removeEventListener('loadedmetadata', onVideoDurationChange);
  el?.removeEventListener('progress', onVideoProgress);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>

<style lang="scss" scoped>
.m-vc-track {
  display: flex;
  align-items: center;
  width: 100%;
  height: 24px;
  touch-action: none;
  cursor: pointer;

  &:focus-visible {
    outline: none;
  }

  &:focus-visible &-bar {
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.5);
  }

  &:focus-visible &-thumb {
    box-shadow:
      0 1px 2px rgba(0, 0, 0, 0.4),
      0 0 0 2px rgba(255, 255, 255, 0.5);
  }

  &:hover &-bar {
    height: 9px;
  }

  &-bar {
    position: relative;
    width: 100%;
    height: 7px;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.3);
    overflow: visible;
    cursor: pointer;
  }

  &-buffered {
    position: absolute;
    top: 0;
    bottom: 0;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.55);
    pointer-events: none;
  }

  &-played {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    border-radius: 2px;
    background: #fff;
    pointer-events: none;
  }

  &-tooltip {
    position: absolute;
    bottom: calc(100% + 12px);
    padding: 2px 6px;
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    transform: translateX(-50%);
    pointer-events: none;
  }

  &-thumb {
    position: absolute;
    top: 50%;
    width: 10px;
    height: 24px;
    border-radius: 5px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
    transform: translate(-50%, -50%);
    pointer-events: none;
  }
}
</style>
