<template>
  <div class="m-vc-time-ticker" :title="tickerText" :aria-label="tickerText">
    <span>{{ elapsedStr }}</span
    ><span aria-hidden="true"> / </span><span>{{ totalStr }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { t } from '@services/l10n';
import * as vutils from './utils';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const currentTime = ref(0);
const duration = ref(0);

const showHours = computed(() => duration.value >= 3600);
const elapsedStr = computed(() => vutils.formatMediaTime(currentTime.value, showHours.value));
const totalStr = computed(() => vutils.formatMediaTime(duration.value, showHours.value));
const tickerText = computed(() =>
  t('memories', '{elapsed} / {total}', { elapsed: elapsedStr.value, total: totalStr.value }),
);

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

function attachVideo() {
  if (!props.video) return;
  currentTime.value = props.video.currentTime || 0;
  if (Number.isFinite(props.video.duration)) {
    duration.value = props.video.duration;
  }
  props.video.addEventListener('timeupdate', onVideoTimeUpdate);
  props.video.addEventListener('durationchange', onVideoDurationChange);
  props.video.addEventListener('loadedmetadata', onVideoDurationChange);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('timeupdate', onVideoTimeUpdate);
  el?.removeEventListener('durationchange', onVideoDurationChange);
  el?.removeEventListener('loadedmetadata', onVideoDurationChange);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>

<style lang="scss" scoped>
.m-vc-time-ticker {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
</style>
