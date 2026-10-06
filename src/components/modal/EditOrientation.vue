<template>
  <div class="edit-orientation" v-if="samples.length">
    {{
      t(
        'memories',
        'This feature rotates images losslessly by updating the EXIF metadata. This approach is known to sometimes not work correctly on certain image types such as HEIC. Make sure you do a test run before using it on multiple images.',
      )
    }}

    <div class="samples">
      <XImg v-for="src of samples" class="sample" :key="src" :src="src" :style="{ transform }" />
      <div class="sample more" v-if="photos.length > samples.length">
        <span>+{{ photos.length - samples.length }}</span>
      </div>
    </div>

    <NcActions :inline="3" class="actions">
      <NcActionButton
        :aria-label="t('memories', 'Rotate Left')"
        :title="t('memories', 'Rotate Left')"
        :disabled="disabled"
        @click="doleft"
      >
        <template #icon> <RotateLeftIcon :size="22" /> </template>
      </NcActionButton>
      <NcActionButton
        :aria-label="t('memories', 'Rotate Right')"
        :title="t('memories', 'Rotate Right')"
        :disabled="disabled"
        @click="doright"
      >
        <template #icon> <RotateRightIcon :size="22" /> </template>
      </NcActionButton>
      <NcActionButton
        :aria-label="t('memories', 'Flip')"
        :title="t('memories', 'Flip')"
        :disabled="disabled"
        @click="doflip"
      >
        <template #icon> <FlipHorizontalIcon :size="22" /> </template>
      </NcActionButton>
    </NcActions>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';

import * as utils from '@services/utils';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import RotateLeftIcon from 'vue-material-design-icons/RotateLeft.vue';
import RotateRightIcon from 'vue-material-design-icons/RotateRight.vue';
import FlipHorizontalIcon from 'vue-material-design-icons/FlipHorizontal.vue';
import XImg from '@components/frame/XImg.vue';

import type { IPhoto } from '@typings';

const NORMAL = [1, 6, 3, 8];
const FLIPPED = [2, 7, 4, 5];

const props = defineProps<{
  photos: IPhoto[];
  disabled?: boolean;
}>();

defineEmits<{
  (e: 'save'): void;
}>();

/** Current state relative to 1 */
const state = ref(1);
/** Full (360) rotations for CSS transition */
const spins = ref(0);

const samples = computed(() => props.photos.slice(0, 8).map((photo) => utils.getPreviewUrl({ photo, size: 512 })));

const transform = computed(() => {
  const f = isflip(state.value) ? -1 : 1;
  const d = spins.value;
  return `${transform1.value} rotate(${d * 360 * f}deg)`;
});

const transform1 = computed((): string | null => {
  if (props.disabled) return null;

  /**
   * 1 = Horizontal (normal)
   * 2 = Mirror horizontal
   * 3 = Rotate 180
   * 4 = Mirror vertical
   * 5 = Mirror horizontal and rotate 270 CW
   * 6 = Rotate 90 CW
   * 7 = Mirror horizontal and rotate 90 CW
   * 8 = Rotate 270 CW
   */
  if (state.value < 1 || state.value > 8) {
    console.error('Invalid orientation state', state.value);
    return null;
  }

  switch (state.value) {
    case 1:
      return 'rotate(0deg)';
    case 2:
      return 'scaleX(-1)';
    case 3:
      return 'rotate(180deg)';
    case 4:
      return 'scaleX(-1) rotate(-180deg)';
    case 5:
      return 'scaleX(-1) rotate(-270deg)';
    case 6:
      return 'rotate(90deg)';
    case 7:
      return 'scaleX(-1) rotate(-90deg)';
    case 8:
      return 'rotate(270deg)';
  }

  return null;
});

/** Reset state to initial */
function reset() {
  state.value = 1;
  spins.value = 0;
}

/**
 * Get target orientation state for a photo.
 * If no change is needed, return null.
 */
function result(photo: IPhoto): number | null {
  const exif = photo.imageInfo?.exif;
  if (!exif) return null;

  let target = Number(exif.Orientation) || 1;
  const oldState = target;

  // Check if state is valid
  if (target < 1 || target > 8) {
    target = 1;
  }

  // Flip state if needed
  if (isflip(state.value)) {
    target = flip(target);
  }

  // Rotate state by index difference
  const cindex = list(state.value).indexOf(state.value);
  const targetList = list(target);
  const sindex = targetList.indexOf(target);
  target = targetList[(cindex + sindex) % targetList.length];

  // No change
  if ((!exif.Orientation && target === 1) || target === oldState) {
    return null;
  }

  return target;
}

function doleft() {
  const current = list(state.value);
  let index = current.indexOf(state.value) - 1;
  if (index < 0) {
    spins.value--;
    index = current.length - 1;
  }
  state.value = current[index];
}

function doright() {
  const current = list(state.value);
  let index = current.indexOf(state.value) + 1;
  if (index === current.length) {
    spins.value++;
    index = 0;
  }
  state.value = current[index];
}

function doflip() {
  state.value = flip(state.value);
}

/** Flip a state in-place */
function flip(flipState: number) {
  if (isflip(flipState)) {
    let i = FLIPPED.indexOf(flipState);
    if (i === 1) i = 3;
    else if (i === 3) i = 1;
    return NORMAL[i];
  } else {
    let i = NORMAL.indexOf(flipState);
    if (i === 1) i = 3;
    else if (i === 3) i = 1;
    return FLIPPED[i];
  }
}

/** Check if a state is flipped */
function isflip(checkState: number) {
  return FLIPPED.includes(checkState);
}

/** Get rotation list for this state (flipped / regular) */
function list(listState: number) {
  return isflip(listState) ? FLIPPED : NORMAL;
}

defineExpose({ reset, result });
</script>

<style scoped lang="scss">
.edit-orientation {
  margin: 4px 0;

  .samples {
    display: grid;
    grid-gap: 5px;
    grid-template-columns: repeat(auto-fit, 80px);
    justify-content: center;
    margin: 6px 0;
    margin-top: 10px;

    .sample {
      border-radius: 10px;
      object-fit: cover;
      width: 100%;
      aspect-ratio: 1 / 1;
      transition: transform 0.2s ease-in-out;

      &:first-child {
        grid-column: span 2;
        grid-row: span 2;
      }
    }

    .more {
      display: flex;
      justify-content: center;
      align-items: center;
      background-color: var(--color-background-dark);

      > span {
        font-size: 1.3em;
        font-weight: 500;
        transform: translate(-3px, -3px);
      }
    }
  }

  .actions {
    justify-content: center;
  }
}
</style>
