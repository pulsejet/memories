<template>
  <div class="m-vc-volume">
    <NcButton
      variant="tertiary-no-background"
      :aria-label="isSilent ? t('memories', 'Unmute') : t('memories', 'Mute')"
      :title="isSilent ? t('memories', 'Unmute') : t('memories', 'Mute')"
      @click="toggleMute"
    >
      <template #icon>
        <span class="m-vc-volume-icon">
          <VolumeOffIcon v-if="isSilent" :size="24" class="m-vc-volume-icon--off" />
          <VolumeLowIcon v-else-if="volume < 0.34" :size="24" class="m-vc-volume-icon--low" />
          <VolumeMediumIcon v-else-if="volume < 0.67" :size="24" class="m-vc-volume-icon--medium" />
          <VolumeHighIcon v-else :size="24" class="m-vc-volume-icon--high" />
        </span>
      </template>
    </NcButton>

    <div class="m-vc-volume-slider">
      <input
        type="range"
        min="0"
        max="100"
        step="1"
        :value="sliderValue"
        :style="{ '--m-vc-fill': `${sliderValue}%` }"
        :aria-label="t('memories', 'Volume')"
        @input="onSliderInput"
        @keydown="onSliderKeyDown"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import VolumeHighIcon from 'vue-material-design-icons/VolumeHigh.vue';
import VolumeMediumIcon from 'vue-material-design-icons/VolumeMedium.vue';
import VolumeLowIcon from 'vue-material-design-icons/VolumeLow.vue';
import VolumeOffIcon from 'vue-material-design-icons/VolumeOff.vue';

import { t } from '@services/l10n';
import { setConfig } from '@services/user-config';

import type { VideoController } from '@typings';

const DEFAULT_UNMUTE_VOLUME = 0.5;

const props = defineProps<{
  video?: VideoController | null;
}>();

const volume = ref(1);
const muted = ref(false);

const isSilent = computed(() => muted.value || volume.value === 0);
const sliderValue = computed(() => (muted.value ? 0 : Math.round(volume.value * 100)));

function onVolumeChange() {
  if (props.video) {
    volume.value = props.video.volume;
    muted.value = props.video.muted;
  }
}

function toggleMute() {
  if (props.video) {
    const value = !isSilent.value;
    if (!value && props.video.volume === 0) {
      props.video.volume = DEFAULT_UNMUTE_VOLUME;
      setConfig('video_volume', DEFAULT_UNMUTE_VOLUME);
    }
    props.video.muted = value;
    setConfig('video_muted', value);
  }
}

function onSliderInput(event: Event) {
  if (props.video) {
    const value = Number((event.target as HTMLInputElement).value) / 100;
    props.video.volume = value;
    props.video.muted = false;
    setConfig('video_volume', value);
    setConfig('video_muted', false);
  }
}

function onSliderKeyDown(event: KeyboardEvent) {
  if (event.key.startsWith('Arrow')) {
    event.stopPropagation();
  }
}

function attachVideo() {
  if (!props.video) return;
  volume.value = props.video.volume;
  muted.value = props.video.muted;
  props.video.addEventListener('volumechange', onVolumeChange);
}

function detachVideo(el: VideoController | null | undefined = props.video) {
  el?.removeEventListener('volumechange', onVolumeChange);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>

<style lang="scss" scoped>
.m-vc-volume {
  display: flex;
  align-items: center;
}

.m-vc-volume-icon {
  display: inline-flex;
  width: 24px;
  height: 24px;
  align-items: center;
  justify-content: center;

  // Speaker cone sits at different x per MDI glyph,
  // shift waves-only icons left to keep it still.
  &--low {
    display: inline-flex;
    transform: translateX(-4px);
  }

  &--medium {
    display: inline-flex;
    transform: translateX(-2px);
  }
}

.m-vc-volume-slider {
  max-width: 0;
  opacity: 0;
  overflow: hidden;
  margin-left: 0;
  transition:
    max-width 0.2s ease-in-out,
    opacity 0.2s ease-in-out,
    margin 0.2s ease-in-out,
    padding 0.2s ease-in-out;

  input {
    display: block;
    width: 80px;
    height: 12px;
    padding: 0;
    border: none;
    background: transparent;
    appearance: none;
    cursor: pointer;

    &::-webkit-slider-runnable-track {
      height: 4px;
      border-radius: 2px;
      background: linear-gradient(to right, #fff var(--m-vc-fill, 0%), rgba(255, 255, 255, 0.35) var(--m-vc-fill, 0%));
    }

    &::-webkit-slider-thumb {
      width: 12px;
      height: 12px;
      margin-top: -4px;
      border: none;
      border-radius: 50%;
      background: #fff;
      appearance: none;
      transition:
        width 0.15s ease-in-out,
        height 0.15s ease-in-out;
    }

    &::-moz-range-track {
      height: 4px;
      border: none;
      border-radius: 2px;
      background: rgba(255, 255, 255, 0.35);
    }

    &::-moz-range-progress {
      height: 4px;
      border: none;
      border-radius: 2px;
      background: #fff;
    }

    &::-moz-range-thumb {
      width: 12px;
      height: 12px;
      border: none;
      border-radius: 50%;
      background: #fff;
    }

    &:focus-visible {
      outline: 2px solid #fff;
      outline-offset: 4px;
    }
  }

  .m-vc-volume:hover &,
  .m-vc-volume:focus-within & {
    max-width: 96px;
    opacity: 1;
    padding: 6px; // Room for the focus outline inside overflow: hidden
  }

  &:hover {
    input {
      &::-webkit-slider-runnable-track {
        height: 6px;
      }

      &::-webkit-slider-thumb {
        width: 14px;
        height: 14px;
        margin-top: -4px;
      }

      &::-moz-range-track,
      &::-moz-range-progress {
        height: 6px;
      }

      &::-moz-range-thumb {
        width: 14px;
        height: 14px;
      }
    }
  }
}
</style>
