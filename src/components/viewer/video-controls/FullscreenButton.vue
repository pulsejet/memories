<template>
  <NcButton
    v-if="!video?.disallowFullscreen"
    variant="tertiary-no-background"
    :aria-label="isFullscreen ? t('memories', 'Exit fullscreen') : t('memories', 'Fullscreen')"
    :title="isFullscreen ? t('memories', 'Exit fullscreen') : t('memories', 'Fullscreen')"
    @click="toggleFullscreen"
  >
    <template #icon>
      <FullscreenExitIcon v-if="isFullscreen" :size="24" />
      <FullscreenIcon v-else :size="24" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { getCurrentInstance, onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue';
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue';

import { t } from '@services/l10n';

import type { VideoController } from '@typings';

interface WebkitContainer extends HTMLElement {
  webkitRequestFullscreen?: () => Promise<void> | void;
}

interface WebkitDoc extends Document {
  webkitFullscreenElement?: Element | null;
  webkitExitFullscreen?: () => Promise<void> | void;
}

const props = defineProps<{
  video?: VideoController | null;
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
  return !!document.fullscreenElement || !!(document as WebkitDoc).webkitFullscreenElement || nativeFullscreen.value;
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
  } else if ((container as WebkitContainer).webkitRequestFullscreen) {
    await (container as WebkitContainer).webkitRequestFullscreen?.();
  } else {
    const video = props.video;
    if (video && typeof video.webkitEnterFullscreen === 'function') {
      video.webkitEnterFullscreen();
      return;
    }
    throw new Error('Fullscreen not supported');
  }

  try {
    if (props.video?.videoWidth && props.video?.videoHeight) {
      const orientation = props.video.videoWidth >= props.video.videoHeight ? 'landscape' : 'portrait';
      await screen.orientation.lock(orientation);
    }
  } catch {
    // Ignore
  }
}

async function exitFullscreen() {
  try {
    screen.orientation.unlock();
  } catch {
    // Ignore
  }
  if (document.fullscreenElement) {
    await document.exitFullscreen();
  }
  const wkDoc = document as WebkitDoc;
  if (wkDoc.webkitFullscreenElement) {
    await wkDoc.webkitExitFullscreen?.();
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

function detachVideo(el: VideoController | null | undefined = props.video) {
  document.removeEventListener('fullscreenchange', onFullscreenChange);
  document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
  el?.removeEventListener('webkitbeginfullscreen', onNativeBegin);
  el?.removeEventListener('webkitendfullscreen', onNativeEnd);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
