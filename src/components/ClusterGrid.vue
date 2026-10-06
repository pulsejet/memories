<template>
  <RecycleScroller
    ref="recycler"
    tabindex="1"
    type-field="cluster_type"
    key-field="cluster_id"
    class="grid-recycler hide-scrollbar-mobile"
    :class="classList"
    :items="clusters"
    :skipHover="true"
    :buffer="400"
    :itemSize="height"
    :itemSecondarySize="width"
    :gridItems="gridItems"
    @resize="resize"
  >
    <template #before>
      <slot name="before"></slot>
    </template>

    <template v-slot="{ item }">
      <div class="grid-item fill-block">
        <Cluster :data="item" :link="link" :counters="counters" @click="click(item)" />
      </div>
    </template>
  </RecycleScroller>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { RecycleScroller } from 'vue-virtual-scroller';

import Cluster from '@components/frame/Cluster.vue';

import type { ICluster } from '@typings';
import * as utils from '@services/utils';

const props = withDefaults(
  defineProps<{
    items: ICluster[];
    maxSize?: number;
    minCols?: number;
    link?: boolean;
    plus?: boolean;
    focus?: boolean;
  }>(),
  {
    maxSize: 180,
    minCols: 3,
    link: true,
    plus: false,
    focus: false,
  },
);

const emit = defineEmits<{
  click: [item: ICluster];
  plus: [];
}>();

const route = useRoute();
const routeIsAlbums = computed(() => route.name === _m.routes.Albums.name);
const windowWidthIsMobile = computed(() => _m.window.isMobile);

const recycler = ref<VueRecyclerType>();
const recyclerWidth = ref(300);

/** Number of items horizontally */
const gridItems = computed(() =>
  // Restrict the number of columns between minCols and the size cap
  Math.max(Math.floor(recyclerWidth.value / props.maxSize), props.minCols),
);

/** Width of the cluster */
const width = computed(() => utils.round(recyclerWidth.value / gridItems.value, 2));

/** Height of the cluster */
const height = computed(() => {
  if (routeIsAlbums.value) {
    // album view: add gap for text below album
    // 4px extra on mobile for mark#2147915
    return width.value + (windowWidthIsMobile.value ? 46 : 42);
  }

  return width.value;
});

/** Classes list on object */
const classList = computed(() => ({
  empty: !props.items.length,
  'cluster--album': routeIsAlbums.value,
}));

/** Whether the clusters should show counters */
const counters = computed(() => !routeIsAlbums.value);

/** List of clusters to display */
const clusters = computed(() => {
  const items = [...props.items];

  // Add plus button if required
  if (props.plus) {
    items.unshift({
      cluster_type: 'plus',
      cluster_id: -1,
      name: '',
      count: 0,
    });
  }

  return items;
});

function click(item: ICluster) {
  switch (item.cluster_type) {
    case 'plus':
      emit('plus');
      break;
    default:
      emit('click', item);
  }
}

function resize() {
  recyclerWidth.value = recycler.value?.$el.clientWidth ?? recyclerWidth.value;
}

onMounted(resize);

watch(
  () => props.items,
  async () => {
    if (props.focus) {
      await nextTick();
      recycler.value?.$el.focus();
    }
  },
);
</script>

<style lang="scss" scoped>
.grid-recycler {
  will-change: scroll-position;
  flex: 1;
  max-height: 100%;
  overflow-y: scroll !important;

  &.empty {
    visibility: hidden;
    flex: 0 0 auto;
    height: 0;
  }

  &:focus {
    outline: none;
  }

  margin: 1px;
  @media (max-width: 768px) {
    &.cluster--album {
      margin: 6px; // mark#2147915
    }
  }

  .grid-item {
    position: relative;
  }
}
</style>
