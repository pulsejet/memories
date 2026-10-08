<template>
  <div class="cluster-hlist">
    <div class="title" v-if="title">
      <div class="name">{{ props.title }}</div>
      <div class="action">
        <router-link v-if="props.link" :to="props.link">{{ t('memories', 'View all') }}</router-link>
      </div>
    </div>

    <div ref="hlist" class="hlist hide-scrollbar">
      <div
        class="item cluster--rounded"
        :class="{ 'cluster--circle': circle(item) }"
        :key="item.cluster_id"
        v-for="item of props.clusters"
      >
        <Cluster :data="item" :link="true" :counters="!routeIs.Explore" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useTemplateRef } from 'vue';

import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import { useRouteState } from '@services/route-state';

import Cluster from '@components/frame/Cluster.vue';

import type { ICluster } from '@typings';

const props = defineProps<{
  stateKey: string;
  clusters: ICluster[];
  title?: string;
  link?: string;
}>();

const hlistRef = useTemplateRef<HTMLDivElement>('hlist');

useRouteState({
  instance: props.stateKey,
  scroll: { hlistRef },
});

function circle(cluster: ICluster): boolean {
  switch (cluster.cluster_type) {
    case 'recognize':
    case 'facerecognition':
      return true;
    default:
      return false;
  }
}
</script>

<style lang="scss" scoped>
.cluster-hlist {
  width: 100%;

  > .title {
    display: flex;

    padding: 4px 16px 14px 14px;
    @media (max-width: 768px) {
      padding-bottom: 8px;
    }

    > .name {
      font-size: 1.1em;
      flex-grow: 1;
    }

    > .action {
      :deep(a) {
        font-size: 0.9em;
        color: var(--color-primary);
      }
    }
  }

  > .hlist {
    $clusterSize: 140px;
    $circleHeight: 176px;
    $clusterSizeM: 120px;
    $circleHeightM: 152px;

    width: 100%;
    padding: 0 6px;

    @media (min-width: 769px) {
      overflow: hidden;
      display: grid;
      grid-gap: 10px;
      grid-template-columns: repeat(auto-fill, minmax(calc(min(50%, $clusterSize) - 5px), 1fr));

      max-height: $clusterSize;
      &:has(.cluster--circle) {
        max-height: $circleHeight;
      }
    }

    @media (max-width: 768px) {
      overflow-x: auto;
      white-space: nowrap;
    }

    > .item {
      display: inline-block;
      margin: 0 2px;
      aspect-ratio: 1;
      position: relative;

      width: $clusterSize;
      @media (max-width: 768px) {
        width: $clusterSizeM;
      }

      &.cluster--circle {
        height: $circleHeight;
        aspect-ratio: unset;

        @media (max-width: 768px) {
          height: $circleHeightM; // font-size: 0.9em;
        }
      }
    }
  }
}
</style>
