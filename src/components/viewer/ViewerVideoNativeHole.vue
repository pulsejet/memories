<!-- Native hole video exposed as a VideoController for the stock controls. -->
<template>
  <div ref="frameEl" class="memories-video-frame" :style="{ width: frameW, height: frameH }" />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import { bus } from '@services/utils/event-bus';
import { videoCommand } from '@native/video-hole';

import type { NativeXVideoState } from '@typings';
import type { IPhoto } from '@typings';

// ---------------------------------------------------------------------------
// Props (written by PsVideo; declared props are proxied by the custom element)
// ---------------------------------------------------------------------------

const props = withDefaults(
  defineProps<{
    src?: string | null;
    photo?: IPhoto | null;
    preload?: string;
    autoplay?: boolean;
    loop?: boolean;
  }>(),
  { preload: 'metadata', autoplay: false, loop: false },
);

// ---------------------------------------------------------------------------
// Frame layout (contain-fit box; zoom/pan rides on the stage mirror transform)
// ---------------------------------------------------------------------------

const frameEl = ref<HTMLDivElement | null>(null);

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

// ---------------------------------------------------------------------------
// Mirrored native state, updated from snapshots
// ---------------------------------------------------------------------------

const state = reactive({
  paused: true,
  playing: false,
  ended: false,
  seeking: false,
  time: 0,
  duration: NaN,
  volume: 1,
  muted: false,
  rate: 1,
  readyState: 0,
  bufferedEnd: 0,
  videoW: 0,
  videoH: 0,
  lastError: null as string | null,
});

// ---------------------------------------------------------------------------
// Host element API (property reads/writes from the controls land here)
// ---------------------------------------------------------------------------

let host: HTMLElement | null = null;

function emitDom(type: string) {
  host?.dispatchEvent(new Event(type));
}

function timeRanges(): TimeRanges {
  const end = state.bufferedEnd;
  const dur = state.duration;
  const ranges = end > 0 ? [{ start: 0, end: Number.isFinite(dur) ? Math.min(end, dur) : end }] : [];
  return {
    length: ranges.length,
    start: (i: number) => ranges[i].start,
    end: (i: number) => ranges[i].end,
  };
}

function setVolumeCommand(v: number) {
  if (!props.src || !Number.isFinite(v)) return;
  videoCommand({ action: 'volume', url: props.src, volume: v });
}

function setRateCommand(v: number) {
  if (!props.src || !Number.isFinite(v)) return;
  videoCommand({ action: 'rate', url: props.src, rate: v });
}

function setMutedCommand(v: boolean) {
  if (!props.src) return;
  videoCommand({ action: 'mute', url: props.src, muted: !!v });
}

/** Expose the VideoController surface on the custom element itself. */
function installControllerApi() {
  host = frameEl.value?.closest?.('viewer-video-native-hole') as HTMLElement | null;
  if (!host) return;
  Object.defineProperties(host, {
    paused: { get: () => state.paused, configurable: true },
    currentTime: { get: () => state.time, set: seekTo, configurable: true },
    duration: { get: () => state.duration, configurable: true },
    volume: { get: () => state.volume, set: setVolumeCommand, configurable: true },
    muted: {
      get: () => state.muted,
      set: setMutedCommand,
      configurable: true,
    },
    playbackRate: { get: () => state.rate, set: setRateCommand, configurable: true },
    readyState: { get: () => state.readyState, configurable: true },
    buffered: { get: timeRanges, configurable: true },
    videoWidth: { get: () => state.videoW, configurable: true },
    videoHeight: { get: () => state.videoH, configurable: true },
  });
}

// ---------------------------------------------------------------------------
// Commands (VideoController methods, called by the controls)
// ---------------------------------------------------------------------------

async function play(): Promise<void> {
  const src = props.src;
  if (!src) throw new Error('ViewerVideoNativeHole: missing src');
  state.lastError = null;
  videoCommand({ action: 'play', url: src, loop: props.loop });
}

function pause(): void {
  if (!props.src) return;
  videoCommand({ action: 'pause', url: props.src });
}

/** Reset to the initial state and rewind native. */
function load(): void {
  if (props.src) {
    videoCommand({ action: 'stop', url: props.src });
  }
  Object.assign(state, {
    paused: true,
    playing: false,
    ended: false,
    seeking: false,
    time: 0,
    duration: NaN,
    readyState: 0,
    bufferedEnd: 0,
    lastError: null,
  });
  emitDom('emptied');
}

function seekTo(value: number) {
  if (!props.src || !Number.isFinite(value)) return;
  state.seeking = true;
  emitDom('seeking');
  videoCommand({ action: 'seek', url: props.src, position: Math.max(0, value) });
}

defineExpose({ play, pause, load });

// ---------------------------------------------------------------------------
// Snapshot sync (diff one native state against local state, fire DOM events)
// ---------------------------------------------------------------------------

function applyMetadata(s: NativeXVideoState) {
  if (s.duration !== null && s.duration !== state.duration) {
    state.duration = s.duration;
    emitDom('loadedmetadata');
    emitDom('durationchange');
  }
  if (s.videoWidth !== null) state.videoW = s.videoWidth;
  if (s.videoHeight !== null) state.videoH = s.videoHeight;
}

function applyAudio(s: NativeXVideoState) {
  if (s.volume !== state.volume || s.muted !== state.muted) {
    state.volume = s.volume;
    state.muted = s.muted;
    emitDom('volumechange');
  }
  if (s.playbackRate !== state.rate) {
    state.rate = s.playbackRate;
    emitDom('ratechange');
  }
}

function applyProgress(s: NativeXVideoState) {
  const prevRs = state.readyState;
  state.readyState = s.readyState;
  if (prevRs < 3 && s.readyState >= 3) emitDom('canplay');
  if (state.seeking && !s.seeking) {
    state.seeking = false;
    emitDom('seeked');
  }
  if (s.currentTime !== state.time) {
    state.time = s.currentTime;
    emitDom('timeupdate');
  }
  if (s.bufferedEnd !== state.bufferedEnd) {
    state.bufferedEnd = s.bufferedEnd;
    emitDom('progress');
  }
}

function applyTransport(s: NativeXVideoState) {
  if (s.ended && !state.ended) {
    state.ended = true;
    emitDom('ended');
  } else if (!s.ended) {
    state.ended = false;
  }
  if (s.paused !== state.paused) {
    state.paused = s.paused;
    emitDom(s.paused ? 'pause' : 'play');
    if (!s.paused && !s.playing) {
      emitDom('waiting');
    }
  }
  if (s.playing !== state.playing) {
    state.playing = s.playing;
    if (s.playing) emitDom('playing');
    else if (!s.paused) emitDom('waiting');
  }
}

function applyError(s: NativeXVideoState) {
  if (s.error && s.error !== state.lastError) {
    state.lastError = s.error;
    emitDom('error');
  } else if (!s.error) {
    state.lastError = null;
  }
}

function onNativeState(s: NativeXVideoState) {
  if (!s || s.url !== props.src) return;
  applyMetadata(s);
  applyAudio(s);
  applyProgress(s);
  applyTransport(s);
  applyError(s);
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

onMounted(() => {
  installControllerApi();
  bus.on('nativex:video:state', onNativeState);
});

onBeforeUnmount(() => {
  bus.off('nativex:video:state', onNativeState);
});
</script>

<style lang="scss" scoped>
.memories-video-frame {
  pointer-events: none;
  background: transparent !important;
}
</style>
