<template>
  <NcButton
    variant="tertiary-no-background"
    :aria-label="isPlaying ? t('memories', 'Pause') : t('memories', 'Play')"
    :title="isPlaying ? t('memories', 'Pause') : t('memories', 'Play')"
    @click="togglePlay"
  >
    <template #icon>
      <PauseIcon v-if="isPlaying" :size="26" />
      <PlayIcon v-else :size="26" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import PauseIcon from 'vue-material-design-icons/Pause.vue';

import { t } from '@services/l10n';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const isPlaying = ref(false);

function onVideoPlay() {
  isPlaying.value = true;
}

function onVideoPause() {
  isPlaying.value = false;
}

async function togglePlay() {
  if (!props.video) return;
  try {
    if (props.video.paused) {
      await props.video.play();
    } else {
      props.video.pause();
    }
  } catch (e) {
    console.warn('Failed to toggle play/pause', e);
  }
}

function attachVideo() {
  if (!props.video) return;
  isPlaying.value = !props.video.paused;
  props.video.addEventListener('play', onVideoPlay);
  props.video.addEventListener('pause', onVideoPause);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('play', onVideoPlay);
  el?.removeEventListener('pause', onVideoPause);
  isPlaying.value = false;
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
