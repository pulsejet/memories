<template>
  <NcButton
    v-if="isSupported"
    variant="tertiary-no-background"
    :aria-label="isPip ? t('memories', 'Exit picture-in-picture') : t('memories', 'Enter picture-in-picture')"
    :title="isPip ? t('memories', 'Exit picture-in-picture') : t('memories', 'Enter picture-in-picture')"
    @click="togglePip"
  >
    <template #icon>
      <PictureInPictureBottomRightIcon :size="24" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import PictureInPictureBottomRightIcon from 'vue-material-design-icons/PictureInPictureBottomRight.vue';

import { t } from '@services/l10n';

import type { VideoController } from '@typings';

const props = defineProps<{
  video?: VideoController | null;
}>();

const isPip = ref(false);
const isSupported = ref(false);

function isPipSupported(video?: VideoController | null) {
  const el = video ?? props.video;
  if (el) {
    if (typeof el.requestPictureInPicture === 'function' && !el.disablePictureInPicture) {
      if ('pictureInPictureEnabled' in document) {
        return document.pictureInPictureEnabled === true;
      }
      return true;
    }
    if (typeof el.webkitSupportsPresentationMode === 'function') {
      try {
        return el.webkitSupportsPresentationMode('picture-in-picture');
      } catch {
        return true;
      }
    }
    if (typeof el.webkitSetPresentationMode === 'function') {
      return true;
    }
    return false;
  }
  if ('pictureInPictureEnabled' in document) {
    return document.pictureInPictureEnabled === true;
  }
  return (
    typeof HTMLVideoElement !== 'undefined' && typeof HTMLVideoElement.prototype.requestPictureInPicture === 'function'
  );
}

function updateSupport() {
  isSupported.value = isPipSupported();
}

function onEnterPip() {
  isPip.value = true;
}

function onLeavePip() {
  isPip.value = false;
}

function onWebkitModeChange() {
  isPip.value = props.video?.webkitPresentationMode === 'picture-in-picture';
}

async function enterPip(video: VideoController) {
  if (video.requestPictureInPicture && !video.disablePictureInPicture) {
    await video.requestPictureInPicture();
    return;
  }
  if (typeof video.webkitSetPresentationMode === 'function') {
    if (video.webkitSupportsPresentationMode?.('picture-in-picture') === false) {
      throw new Error('Picture-in-picture not supported');
    }
    video.webkitSetPresentationMode('picture-in-picture');
    return;
  }
  throw new Error('Picture-in-picture not supported');
}

async function exitPip(el: VideoController | null | undefined = props.video) {
  if (document.pictureInPictureElement) {
    if (!el || document.pictureInPictureElement === (el as unknown as Element)) {
      await document.exitPictureInPicture();
    }
    return;
  }
  const video = el;
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
  updateSupport();
  if (!props.video) return;
  isPip.value =
    document.pictureInPictureElement === (props.video as unknown as Element) ||
    props.video.webkitPresentationMode === 'picture-in-picture';
  props.video.addEventListener('enterpictureinpicture', onEnterPip);
  props.video.addEventListener('leavepictureinpicture', onLeavePip);
  props.video.addEventListener('webkitpresentationmodechanged', onWebkitModeChange);
}

function detachVideo(el: VideoController | null | undefined = props.video) {
  if (isPip.value) void exitPip(el).catch(() => {});
  el?.removeEventListener('enterpictureinpicture', onEnterPip);
  el?.removeEventListener('leavepictureinpicture', onLeavePip);
  el?.removeEventListener('webkitpresentationmodechanged', onWebkitModeChange);
}

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
