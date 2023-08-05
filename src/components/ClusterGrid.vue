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
    :itemSize="height"
    :itemSecondarySize="width"
    :gridItems="gridItems"
    @resize="resize"
  >
    <template #before>
      <slot name="before" />
    </template>

    <template v-slot="{ item }">
      <div class="grid-item fill-block">
        <Cluster :data="item" :link="link" :class="clusterClasses" :counters="counters" @click="click(item)" />
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
    recyclerWidth: 300,
  }),

  mounted() {
    this.resize();
  },

  computed: {
    /** Height of the cluster */
    height() {
      if (this.routeIsAlbums) {
        // album view: add gap for text below album
        return this.width + 42;
      }

      return this.width;
    },

    /** Width of the cluster */
    width() {
      // Restrict the number of columns between minCols and the size cap
      return Math.floor(this.recyclerWidth / this.gridItems);
    },

    /** Number of items horizontally */
    gridItems() {
      return Math.max(Math.floor(this.recyclerWidth / this.maxSize), this.minCols);
    },

    /** Classes list on cluster object */
    clusterClasses() {
      return {
        'cluster--album': this.routeIsAlbums,
      };
    },

    /** Whether the clusters should show counters */
    counters() {
      return !this.routeIsAlbums;
    },

    /** List of clusters to display */
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
      this.recyclerWidth = (<any>this.$refs.recycler).$el.clientWidth;
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
