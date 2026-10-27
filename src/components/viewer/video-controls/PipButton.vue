<template>
  <NcButton
    variant="tertiary-no-background"
    :aria-label="isPip ? t('memories', 'Exit picture-in-picture') : t('memories', 'Enter picture-in-picture')"
    :title="isPip ? t('memories', 'Exit picture-in-picture') : t('memories', 'Enter picture-in-picture')"
    @click="togglePip"
  >
    <template #icon>
      <PictureInPictureBottomRightIcon :size="26" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import PictureInPictureBottomRightIcon from 'vue-material-design-icons/PictureInPictureBottomRight.vue';

import { t } from '@services/l10n';

interface WebkitPip {
  webkitSetPresentationMode?: (mode: 'picture-in-picture' | 'inline') => void;
  webkitPresentationMode?: string;
  webkitSupportsPresentationMode?: (mode: string) => boolean;
}

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const isPip = ref(false);

function onEnterPip() {
  isPip.value = true;
}

function onLeavePip() {
  isPip.value = false;
}

function onWebkitModeChange() {
  isPip.value =
    (props.video as unknown as WebkitPip | null | undefined)?.webkitPresentationMode === 'picture-in-picture';
}

async function enterPip(video: HTMLVideoElement) {
  if (video.requestPictureInPicture && !video.disablePictureInPicture) {
    await video.requestPictureInPicture();
    return;
  }
  const webkitVideo = video as unknown as WebkitPip;
  if (typeof webkitVideo.webkitSetPresentationMode === 'function') {
    if (webkitVideo.webkitSupportsPresentationMode?.('picture-in-picture') === false) {
      throw new Error('Picture-in-picture not supported');
    }
    webkitVideo.webkitSetPresentationMode('picture-in-picture');
    return;
  }
  throw new Error('Picture-in-picture not supported');
}

async function exitPip() {
  if (document.pictureInPictureElement) {
    await document.exitPictureInPicture();
    return;
  }
  const video = props.video as unknown as WebkitPip | null | undefined;
  if (
    video &&
    typeof video.webkitSetPresentationMode === 'function' &&
    video.webkitPresentationMode === 'picture-in-picture'
  ) {
    video.webkitSetPresentationMode('inline');
  }
}

async function togglePip() {
  if (!props.video) return;
  try {
    if (isPip.value) {
      await exitPip();
    } else {
      await enterPip(props.video);
    }
  } catch (e) {
    console.warn('Failed to toggle picture-in-picture', e);
  }
}

function attachVideo() {
  if (!props.video) return;
  isPip.value =
    document.pictureInPictureElement === props.video ||
    (props.video as unknown as WebkitPip).webkitPresentationMode === 'picture-in-picture';
  props.video.addEventListener('enterpictureinpicture', onEnterPip);
  props.video.addEventListener('leavepictureinpicture', onLeavePip);
  props.video.addEventListener('webkitpresentationmodechanged', onWebkitModeChange);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('enterpictureinpicture', onEnterPip);
  el?.removeEventListener('leavepictureinpicture', onLeavePip);
  el?.removeEventListener('webkitpresentationmodechanged', onWebkitModeChange);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
