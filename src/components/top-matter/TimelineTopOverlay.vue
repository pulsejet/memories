<template>
  <div class="top-overlay" :style="{ top }" :class="{ show: !!text }">{{ text }}</div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import * as utils from '@services/utils';

import type { IHeadRow, IPhoto } from '@typings';

export default defineComponent({
  name: 'TimelineTopOverlay',

  data: () => ({
    text: String(),
    top: String(),
  }),

  props: {
    heads: {
      type: Map as PropType<Map<number, IHeadRow>>,
      required: true,
    },

    container: {
      type: Element,
      required: false,
    },

    recycler: {
      type: Element,
      required: false,
    },
  },

  methods: {
    refresh() {
      this.text = this.getText() ?? String();
    },

    getText() {
      // Get position of recycler
      const rrect = this.recycler?.getBoundingClientRect();
      if (!rrect) return; // ??

      // Get position of container
      const crect = this.container?.getBoundingClientRect();
      if (!crect) return; // ??
      this.top = `${rrect.top - crect.top}px`;

      // Get photo just below the top of the container
      const elem: any = document
        .elementsFromPoint(rrect.left + 5, rrect.top + 50)
        .find((e) => e.classList.contains('p-outer-super'));
      const overPhoto: IPhoto | null = elem?.__vue__?.data;

      // If no photo is round, no overlay to show
      if (!overPhoto) return;

      // If this is the first photo, there is an extra condition
      // to check if the photo is actually above the container
      if (overPhoto.dispRowNum === 0 && elem.getBoundingClientRect().top > crect.top) {
        return;
      }

      // Get the header from the dayid of the photo
      // Do not show overlay for single-row days
      const head = this.heads.get(overPhoto.dayid);
      if (!head || (head.day?.rows?.length ?? 0) <= 1) {
        return;
      }

      return utils.getHeadRowName(head);
    },
  },
});
</script>

<style lang="scss" scoped>
.top-overlay {
  position: absolute;
  top: -2px;
  left: 3px;
  height: 40px;
  width: 100%;
  z-index: 1;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.3) 60%, transparent 100%);
  mask-image: linear-gradient(to right, black 0%, black 40%, transparent 80%, transparent 100%);
  color: white;
  display: flex;
  align-items: center;
  padding: 0 6px;
  font-size: 14px;
  font-weight: 500;
  user-select: none;
  pointer-events: none;

  transition: opacity 0.2s ease;
  opacity: 0;
  &.show {
    opacity: 1;
  }

  @media (max-width: 768px) {
    mask-image: none;
    left: 0;
    padding-left: 12px;
  }
}
</style>
