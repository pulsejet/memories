<template>
  <div class="top-overlay" :class="{ show: !!text }">{{ text }}</div>
</template>

<script setup lang="ts">
import { ref } from 'vue';

import * as utils from '@services/utils/common';

import type { IHeadRow, IRow } from '@typings';

defineOptions({
  name: 'TimelineTopOverlay',
});

const props = defineProps<{
  heads: Map<number, IHeadRow>;
  list: IRow[];
  beforeHeight: number;
  recycler?: VueRecyclerType | null;
}>();

const text = ref(String());

function refresh() {
  text.value = getText() ?? String();
}

function getText() {
  if (!props.recycler?.$el || !props.list.length) return;

  // Read the scroll position of the recycler (layout).
  const scrollTop = props.recycler.$el.scrollTop;

  // Still over the before slot: nothing to show.
  if (scrollTop < props.beforeHeight) return;

  // Row at the top edge (offsets exclude the before slot).
  const topRowIdx = props.recycler.findItemIndex(scrollTop - props.beforeHeight);
  const topRow = props.list[topRowIdx];
  if (!topRow) return;

  // A visible header needs no overlay.
  if (topRow.type === 0) return;

  // Avoid barely-visible rows, must have minimum height.
  const belowIdx = props.recycler.findItemIndex(50 + scrollTop - props.beforeHeight);
  const belowRow = props.list[belowIdx];
  if (belowRow?.dayId !== topRow.dayId) return;

  // Do not show overlay for single-row days.
  const head = props.heads.get(topRow.dayId);
  if (!head || (head.day?.rows?.length ?? 0) <= 1) {
    return;
  }

  return utils.getHeadRowName(head);
}

defineExpose({ refresh });
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
