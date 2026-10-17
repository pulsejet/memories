<template>
  <div class="cluster-hlist">
    <div class="title" v-if="title">
      <div class="name">{{ props.title }}</div>
      <div class="action">
        <router-link v-if="props.link" :to="props.link">{{ t('memories', 'View all') }}</router-link>
      </div>
    </div>

    <RecycleScroller
      ref="recycler"
      class="hlist hide-scrollbar"
      direction="horizontal"
      :items="props.clusters"
      key-field="cluster_id"
      :item-size="itemSize"
      :skip-hover="true"
      :style="{ height: `${listHeight}px` }"
      :disable-transform="true"
    >
      <template #default="{ item }">
        <div class="item cluster--rounded" :class="{ 'cluster--circle': props.circle }">
          <Cluster :data="item" :link="true" :counters="!routeIs.Explore" />
        </div>
      </template>
    </RecycleScroller>
  </div>
</template>

<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { RecycleScroller } from '@radialapps/vue-virtual-scroller';

import { t } from '@services/l10n';
import { useRouteIs } from '@services/route-is';
import { useRouteState } from '@services/route-state';
import { windowDims } from '@services/viewport';

import Cluster from '@components/frame/Cluster.vue';

import type { ICluster } from '@typings';

const props = defineProps<{
  stateKey: string;
  clusters: ICluster[];
  title?: string;
  link?: string;
  circle?: boolean;
}>();

const recycler = useTemplateRef<VueRecyclerType>('recycler');
const routeIs = useRouteIs();

useRouteState({
  instance: props.stateKey,
  scroll: { recycler },
});

const CLUSTER_SIZE = 140;
const CLUSTER_SIZE_MOBILE = 120;
const CIRCLE_HEIGHT = 176;
const CIRCLE_HEIGHT_MOBILE = 152;
const ITEM_GAP = 4; // 2px margin on each side

const clusterSize = computed(() => (windowDims.isMobile ? CLUSTER_SIZE_MOBILE : CLUSTER_SIZE));
const circleHeight = computed(() => (windowDims.isMobile ? CIRCLE_HEIGHT_MOBILE : CIRCLE_HEIGHT));

const itemSize = computed(() => clusterSize.value + ITEM_GAP);
const clusterWidth = computed(() => `${clusterSize.value}px`);
const circleHeightPx = computed(() => `${circleHeight.value}px`);
const listHeight = computed(() => (props.circle ? circleHeight.value : clusterSize.value));
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
    width: 100%;
    padding: 0 6px;
    overflow-x: auto;
    overflow-y: hidden;

    :deep(.vue-recycle-scroller__item-view) {
      display: flex;
    }

    .item {
      flex: 1;
      margin: 0 2px;
      aspect-ratio: 1;
      position: relative;

      width: v-bind(clusterWidth);

      &.cluster--circle {
        height: v-bind(circleHeightPx);
        aspect-ratio: unset;
      }
    }
  }
}
</style>
