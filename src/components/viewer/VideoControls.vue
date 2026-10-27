<template>
  <NcButton
    variant="tertiary-no-background"
    :aria-label="isPlaying ? t('memories', 'Pause') : t('memories', 'Play')"
    :title="isPlaying ? t('memories', 'Pause') : t('memories', 'Play')"
    @click="togglePlay"
  >
    <template #icon>
      <PauseIcon v-if="isPlaying" :size="24" />
      <PlayIcon v-else :size="24" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import PauseIcon from 'vue-material-design-icons/Pause.vue';

import { t } from '@services/l10n';

import type { MediaPlayerElement } from 'vidstack/elements';

type VideoElement = HTMLVideoElement | MediaPlayerElement;

const props = defineProps<{
  video?: VideoElement | null;
}>();

const isPlaying = ref(false);

function onVideoPlay() {
  isPlaying.value = true;
}

function onVideoPause() {
  isPlaying.value = false;
}

function attachVideo() {
  if (!props.video) return;
  isPlaying.value = !props.video.paused;
  props.video.addEventListener('play', onVideoPlay);
  props.video.addEventListener('pause', onVideoPause);
}

function detachVideo() {
  props.video?.removeEventListener('play', onVideoPlay);
  props.video?.removeEventListener('pause', onVideoPause);
  isPlaying.value = false;
}

async function togglePlay() {
  if (!props.video) return;
  if (props.video.paused) {
    try {
      await props.video.play();
    } catch {}
  } else {
    try {
      await props.video.pause();
    } catch {}
  }
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
