<template>
  <div class="cluster-hlist">
    <div class="title" v-if="title">
      <div class="name">{{ title }}</div>
      <div class="action">
        <router-link v-if="link" :to="link">{{ t('memories', 'View all') }}</router-link>
      </div>
    </div>

    <div class="hlist hide-scrollbar">
      <div class="item" v-for="item of clusters" :key="item.cluster_id">
        <Cluster :data="item" :link="true" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';
import type { ICluster } from '../types';
import Cluster from './frame/Cluster.vue';

export default defineComponent({
  name: 'ClusterHList',

  components: {
    Cluster,
  },

  props: {
    clusters: {
      type: Array as PropType<ICluster[]>,
      required: true,
    },
    title: {
      type: String,
      required: false,
    },
    link: {
      type: String,
      required: false,
    },
  },
});
</script>

<style lang="scss" scoped>
.cluster-hlist {
  width: 100%;

  > .title {
    padding: 4px 18px 8px 16px;
    display: flex;

    > .name {
      font-size: 18px;
      flex-grow: 1;
    }

    > .action {
      :deep a {
        color: var(--color-primary);
      }
    }
  }

  > .hlist {
    width: 100%;
    overflow-x: auto;
    white-space: nowrap;
    padding: 0 8px;

    > .item {
      display: inline-block;
      margin: 0 2px;
      height: 120px;
      aspect-ratio: 1;
      position: relative;
    }
  }
}
</style>
