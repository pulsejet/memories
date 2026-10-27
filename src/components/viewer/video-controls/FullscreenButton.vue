<template>
  <NcButton
    variant="tertiary-no-background"
    :aria-label="isFullscreen ? t('memories', 'Exit fullscreen') : t('memories', 'Fullscreen')"
    :title="isFullscreen ? t('memories', 'Exit fullscreen') : t('memories', 'Fullscreen')"
    @click="toggleFullscreen"
  >
    <template #icon>
      <FullscreenExitIcon v-if="isFullscreen" :size="26" />
      <FullscreenIcon v-else :size="26" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { getCurrentInstance, onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue';
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue';

import { t } from '@services/l10n';

interface WebkitContainer extends HTMLElement {
  webkitRequestFullscreen?: () => Promise<void> | void;
}

interface WebkitDoc extends Document {
  webkitFullscreenElement?: Element | null;
  webkitExitFullscreen?: () => Promise<void> | void;
}

interface WebkitVideo extends HTMLVideoElement {
  webkitEnterFullscreen?: () => void;
  webkitSupportsFullscreen?: boolean;
}

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const isFullscreen = ref(false);
const nativeFullscreen = ref(false);

function containerEl() {
  const fromVideo = props.video?.closest?.('.video-container') as HTMLElement | null;
  if (fromVideo) return fromVideo;
  const el = getCurrentInstance()?.proxy?.$el as HTMLElement | null;
  return (el?.closest?.('.video-container') as HTMLElement | null) ?? null;
}

function isFullscreenActive() {
  return (
    !!document.fullscreenElement ||
    !!(document as WebkitDoc).webkitFullscreenElement ||
    nativeFullscreen.value
  );
}

function onFullscreenChange() {
  isFullscreen.value = isFullscreenActive();
}

function onNativeBegin() {
  nativeFullscreen.value = true;
  onFullscreenChange();
}

function onNativeEnd() {
  nativeFullscreen.value = false;
  onFullscreenChange();
}

async function enterFullscreen(container: HTMLElement) {
  if (container.requestFullscreen) {
    await container.requestFullscreen();
    return;
  }
  if ((container as WebkitContainer).webkitRequestFullscreen) {
    await (container as WebkitContainer).webkitRequestFullscreen?.();
    return;
  }
  const video = props.video as WebkitVideo | null | undefined;
  if (video && typeof video.webkitEnterFullscreen === 'function') {
    video.webkitEnterFullscreen();
    return;
  }
  throw new Error('Fullscreen not supported');
}

async function exitFullscreen() {
  if (document.fullscreenElement) {
    await document.exitFullscreen();
    return;
  }
  const doc = document as WebkitDoc;
  if (doc.webkitFullscreenElement) {
    await doc.webkitExitFullscreen?.();
  }
}

async function toggleFullscreen() {
  try {
    if (isFullscreenActive()) {
      await exitFullscreen();
    } else {
      const container = containerEl();
      if (!container) return;
      await enterFullscreen(container);
    }
  } catch (e) {
    console.warn('Failed to toggle fullscreen', e);
  }
}

function attachVideo() {
  document.addEventListener('fullscreenchange', onFullscreenChange);
  document.addEventListener('webkitfullscreenchange', onFullscreenChange);
  props.video?.addEventListener('webkitbeginfullscreen', onNativeBegin);
  props.video?.addEventListener('webkitendfullscreen', onNativeEnd);
  isFullscreen.value = isFullscreenActive();
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  document.removeEventListener('fullscreenchange', onFullscreenChange);
  document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
  el?.removeEventListener('webkitbeginfullscreen', onNativeBegin);
  el?.removeEventListener('webkitendfullscreen', onNativeEnd);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
