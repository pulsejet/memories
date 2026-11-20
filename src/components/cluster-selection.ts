import { inject, provide, ref, type InjectionKey, type Ref } from 'vue';

import type { ICluster } from '@typings';

/** Cluster selection shared between ClusterView and its top matter. */
type ClusterSelection = {
  items: Ref<ICluster[]>;
  refresh: () => Promise<void>;
};

const clusterSelectionKey: InjectionKey<ClusterSelection> = Symbol('memories-cluster-selection');

/** Provide cluster selection; must be called synchronously in ClusterView setup. */
export function provideClusterSelection(refresh: () => Promise<void>) {
  const items = ref<ICluster[]>([]);
  provide(clusterSelectionKey, { items, refresh });
  return items;
}

/** Cluster selection, or null outside ClusterView. */
export function useClusterSelection() {
  return inject(clusterSelectionKey, null);
}
