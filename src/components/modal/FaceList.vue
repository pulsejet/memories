<template>
  <div class="outer">
    <div class="search">
      <NcTextField
        :autofocus="true"
        v-model="search"
        :label="t('memories', 'Search')"
        :placeholder="t('memories', 'Search')"
      >
        <MagnifyIcon :size="16" />
      </NcTextField>
    </div>

    <ClusterGrid
      v-if="list"
      :items="filteredList"
      :maxSize="120"
      :link="false"
      :plus="plus"
      @click="click"
      @plus="addFace"
    />
    <div v-else>
      {{ t('memories', 'Loading …') }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, markRaw, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';
import Fuse from 'fuse.js';

import { showError } from '@services/utils/dialog';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import ClusterGrid from '@components/ClusterGrid.vue';

import { t } from '@services/l10n';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';

import type { ICluster, IFace } from '@typings';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

defineOptions({
  name: 'FaceList',
});

const props = defineProps<{
  plus?: boolean;
}>();

const emit = defineEmits<{
  (e: 'select', face: IFace): void;
}>();

const route = useRoute();

const list = ref<IFace[] | null>(null);
const fuse = ref<Fuse<IFace> | null>(null);
const search = ref(String());

const user = computed(() => route.params.user?.toString());
const name = computed(() => route.params.name?.toString());
const backend = computed(() => route.name as 'recognize' | 'facerecognition');

const filteredList = computed(() => {
  if (!list.value || !search.value || !fuse.value) return list.value ?? [];
  return fuse.value.search(search.value).map((r) => r.item);
});

onMounted(() => {
  refresh();
});

async function refresh() {
  try {
    list.value = null;
    const faces = await dav.getFaceList(backend.value);
    list.value = faces.filter((c: IFace) => c.user_id === user.value && String(c.name || c.cluster_id) !== name.value);
    fuse.value = markRaw(new Fuse(list.value, { keys: ['name'] }));
  } catch (e) {
    showError(t('memories', 'Failed to load faces'));
    console.error(e);
  }
}

async function addFace() {
  let name = String();

  try {
    const input = await utils.prompt({
      message: t('memories', 'Create a new face with this name?'),
      title: t('memories', 'Create new face'),
      name: t('memories', 'Name'),
    });

    name = input?.trim() ?? String();
    if (!name) return;

    // Create new directory in WebDAV
    await dav.recognizeCreateFace(user.value, name);

    return selectNew(name);
  } catch (e: any) {
    // Directory already exists
    if (e.status === 405) return selectNew(name);

    showError(t('memories', 'Failed to create face'));
  }
}

function selectNew(faceName: string) {
  emit('select', {
    cluster_id: faceName,
    cluster_type: 'recognize',
    count: 0,
    name: faceName,
    user_id: user.value,
  });
}

function click(item: ICluster) {
  emit('select', item as IFace);
}
</script>

<style lang="scss" scoped>
.outer {
  width: 100%;
  max-height: calc(90vh - 80px - 4em);
  overflow: hidden;
  display: flex;
  flex-direction: column;

  .search {
    margin-bottom: 10px;
  }
}
</style>
