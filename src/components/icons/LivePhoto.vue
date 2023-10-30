<template>
  <span
    v-bind="$attrs"
    :aria-hidden="!title"
    :aria-label="title"
    :style="{ width: `${size}px`, height: `${size}px` }"
    class="material-design-icon live-photo-icon"
    :class="{ spin }"
    role="img"
    @click="$emit('click', $event)"
  >
    <svg :fill="fillColor" class="material-design-icon__svg ring" :width="size" :height="size" viewBox="0 0 24 24">
      <path
        d="M 22,12 C 22,6.46 17.54,2 12,2 10.83,2 9.7,2.19 8.62,2.56 L 9.32,4.5 C 10.17,4.16 11.06,3.97 12,3.97 c 4.41,0 8.03,3.62 8.03,8.03 0,4.41 -3.62,8.03 -8.03,8.03 -4.41,0 -8.03,-3.62 -8.03,-8.03 0,-0.94 0.19,-1.88 0.53,-2.72 L 2.56,8.62 C 2.19,9.7 2,10.83 2,12 2,17.54 6.46,22 12,22 17.54,22 22,17.54 22,12 M 5.47,3.97 C 6.32,3.97 7,4.68 7,5.47 7,6.32 6.32,7 5.47,7 4.68,7 3.97,6.32 3.97,5.47 c 0,-0.79 0.71,-1.5 1.5,-1.5 z"
      ></path>
    </svg>

    <svg
      :fill="fillColor"
      class="material-design-icon__svg play"
      :class="{ visible: !playing }"
      :width="size"
      :height="size"
      viewBox="0 0 24 24"
    >
      <path d="M 10,16.5 16,12 10,7.5"></path>
    </svg>

    <svg
      v-if="playing"
      :fill="fillColor"
      class="material-design-icon__svg pause"
      :class="{ visible: playing }"
      :width="size"
      :height="size"
      viewBox="0 0 24 24"
    >
      <path d="m 9,9 h 2 v 6 H 9 m 4,-6 h 2 v 6 h -2" />
    </svg>
  </span>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'LivePhoto',
  emits: ['click'],
  props: {
    title: {
      type: String,
    },
    fillColor: {
      type: String,
      default: 'currentColor',
    },
    size: {
      type: Number,
      default: 24,
    },
    spin: {
      type: Boolean,
      default: false,
    },
    playing: {
      type: Boolean,
      default: false,
    },
  },
});
</script>

<style scoped lang="scss">
.live-photo-icon {
  position: relative;
  pointer-events: none;

  > svg {
    position: absolute;
  }

  &.spin > .ring {
    animation: spin 1s linear infinite;
  }

  > .play,
  > .pause {
    opacity: 0;
    transition: opacity 0.2s ease-in-out;

    &.visible {
      opacity: 1;
    }
  }

  @keyframes spin {
    100% {
      transform: rotate(360deg);
    }
  }
}
</style>
