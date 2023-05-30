<template>
  <RecycleScroller
    ref="recycler"
    type-field="cluster_type"
    key-field="cluster_id"
    class="grid-recycler hide-scrollbar-mobile"
    :class="{ empty: !items.length }"
    :items="clusters"
    :skipHover="true"
    :buffer="400"
    :itemSize="itemSize"
    :gridItems="gridItems"
    @resize="resize"
  >
    <template #before>
      <slot name="before" />
    </template>

    <template v-slot="{ item }">
      <div class="grid-item fill-block">
        <Cluster :data="item" @click="click(item)" :link="link" />
      </div>
    </template>
  </RecycleScroller>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import Cluster from './frame/Cluster.vue';
import type { ICluster } from '../types';

export default defineComponent({
  name: 'ClusterGrid',

  components: {
    Cluster,
  },

  props: {
    items: {
      type: Array<ICluster>,
      required: true,
    },
    maxSize: {
      type: Number,
      default: 180,
    },
    minCols: {
      type: Number,
      default: 3,
    },
    link: {
      type: Boolean,
      default: true,
    },
    plus: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    itemSize: 200,
    gridItems: 5,
  }),

  mounted() {
    this.resize();
  },

  computed: {
    clusters() {
      const items = [...this.items];

      // Add plus button if required
      if (this.plus) {
        items.unshift({
          cluster_type: 'plus',
          cluster_id: -1,
          name: '',
          count: 0,
        });
      }

      return items;
    },
  },

  methods: {
    click(item: ICluster) {
      switch (item.cluster_type) {
        case 'plus':
          this.$emit('plus');
          break;
        default:
          this.$emit('click', item);
      }
    },

    resize() {
      // Restrict the number of columns between minCols and the size cap
      const w = (<any>this.$refs.recycler).$el.clientWidth;
      this.gridItems = Math.max(Math.floor(w / this.maxSize), this.minCols);
      this.itemSize = Math.floor(w / this.gridItems);
    },
  },
});
</script>

<style lang="scss" scoped>
.grid-recycler {
  will-change: scroll-position;
  flex: 1;
  max-height: 100%;
  overflow-y: scroll !important;
  margin: 1px;
  &.empty {
    visibility: hidden;
  }
}
.grid-item {
  position: relative;
}
</style>
