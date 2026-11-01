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
import { onMounted, ref } from 'vue';
import { useEventListener } from '@vueuse/core';

import NcButton from '@nextcloud/vue/components/NcButton';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import PauseIcon from 'vue-material-design-icons/Pause.vue';

import { t } from '@services/l10n';

import type { VideoController } from '@typings';

const props = defineProps<{
  video?: VideoController | null;
}>();

const isPlaying = ref(false);

useEventListener(
  () => props.video,
  ['play', 'playing'],
  () => {
    isPlaying.value = true;
  },
);

useEventListener(
  () => props.video,
  ['pause', 'ended'],
  () => {
    isPlaying.value = false;
  },
);

onMounted(() => {
  isPlaying.value = props.video?.paused === false;
});

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
</script>
