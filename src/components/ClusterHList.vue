<template>
  <div class="cluster-hlist">
    <div class="title" v-if="title">
      <div class="name">{{ title }}</div>
      <div class="action">
        <router-link v-if="link" :to="link">{{ t('memories', 'View all') }}</router-link>
      </div>
    </div>

    <div class="hlist hide-scrollbar">
      <div
        class="item cluster--rounded"
        :class="{ 'cluster--circle': circle(item) }"
        :key="item.cluster_id"
        v-for="item of clusters"
      >
        <Cluster :data="item" :link="true" :counters="!routeIsExplore" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';

import Cluster from './frame/Cluster.vue';

import type { ICluster } from '@typings';

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

  methods: {
    circle(cluster: ICluster): boolean {
      switch (cluster.cluster_type) {
        case 'recognize':
        case 'facerecognition':
          return true;
        default:
          return false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.cluster-hlist {
  width: 100%;

  > .title {
    padding: 4px 16px 8px 14px;
    display: flex;

    > .name {
      font-size: 1.1em;
      flex-grow: 1;
    }

    > .action {
      :deep a {
        font-size: 0.9em;
        color: var(--color-primary);
      }
    }
  }

  > .hlist {
    width: 100%;
    overflow-x: auto;
    white-space: nowrap;
    padding: 0 6px;

    > .item {
      display: inline-block;
      margin: 0 2px;
      width: 120px;
      aspect-ratio: 1;
      position: relative;

      &.cluster--circle {
        height: 156px;
        aspect-ratio: unset;

        @media (max-width: 768px) {
          height: 152px; // font-size: 0.9em;
        }
      }
    }
  }
}
</style>
