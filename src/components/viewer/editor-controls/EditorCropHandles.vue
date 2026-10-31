<template>
  <div
    class="me-crop-box"
    :class="{
      'is-crop-mode': cropMode,
      'is-active': active,
      'is-animated': animated,
    }"
    :style="boxStyle"
  >
    <template v-if="cropMode">
      <div class="me-crop-grid" aria-hidden="true">
        <span class="me-grid-line is-v" style="left: 33.3333%" />
        <span class="me-grid-line is-v" style="left: 66.6667%" />
        <span class="me-grid-line is-h" style="top: 33.3333%" />
        <span class="me-grid-line is-h" style="top: 66.6667%" />
        <template v-if="straightening">
          <span v-for="i in FINE_LINES" :key="`v${i}`" class="me-grid-line is-v is-fine" :style="{ left: `${(i * 100) / 9}%` }" />
          <span v-for="i in FINE_LINES" :key="`h${i}`" class="me-grid-line is-h is-fine" :style="{ top: `${(i * 100) / 9}%` }" />
        </template>
      </div>

      <div
        v-for="h in HANDLES"
        :key="h"
        class="me-crop-handle"
        :class="`is-${h}`"
        role="slider"
        :aria-label="`Crop ${h} handle`"
        @pointerdown.stop.prevent="emit('handle-down', h, $event)"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { CropHandle } from './types';

const props = withDefaults(
  defineProps<{
    width: number;
    height: number;
    offsetX?: number;
    offsetY?: number;
    cropMode?: boolean;
    active?: boolean;
    straightening?: boolean;
    animated?: boolean;
  }>(),
  {
    offsetX: 0,
    offsetY: 0,
    cropMode: true,
    active: false,
    straightening: false,
    animated: false,
  },
);

const emit = defineEmits<{
  'handle-down': [handle: CropHandle, e: PointerEvent];
}>();

const HANDLES: CropHandle[] = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const FINE_LINES = [1, 2, 4, 5, 7, 8];

const boxStyle = computed(() => ({
  width: `${Math.max(1, props.width)}px`,
  height: `${Math.max(1, props.height)}px`,
  transform: `translate(-50%, -50%) translate3d(${props.offsetX}px, ${props.offsetY}px, 0)`,
}));
</script>
