<template>
  <RecycleScroller
    ref="recycler"
    class="grid-recycler hide-scrollbar-mobile"
    :class="{ empty: !items.length }"
    :items="items"
    :skipHover="true"
    :buffer="400"
    :itemSize="itemSize"
    :gridItems="gridItems"
    :updateInterval="100"
    key-field="cluster_id"
    @resize="resize"
  >
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
    link: {
      type: Boolean,
      default: true,
    },
  },

  data: () => ({
    itemSize: 200,
    gridItems: 5,
  }),

  mounted() {
    this.resize();
  },

  methods: {
    click(item: ICluster) {
      this.$emit('click', item);
    },

    resize() {
      const w = (<any>this.$refs.recycler).$el.clientWidth;
      this.gridItems = Math.max(Math.floor(w / this.maxSize), 3);
      this.itemSize = Math.floor(w / this.gridItems);
    },
  },
});
</script>

<style lang="scss" scoped>
.grid-recycler {
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
