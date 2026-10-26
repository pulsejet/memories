<template>
  <div v-if="available" class="search-places">
    <NcButton :disabled="selected.length >= 8" @click="open">{{ t('memories', 'Add a place') }}</NcButton>
    <span v-if="selected.length">{{ t('memories', 'Place filters') }}</span>
    <NcButton
      v-for="id in selected"
      :key="id"
      :aria-label="t('memories', 'Remove {name} from filter', { name: label(id) })"
      @click="remove(id)"
    >
      {{ id.startsWith('-') ? '−' : '+' }} {{ label(id) }} &times;
    </NcButton>
    <Modal v-if="show" ref="modal" size="normal" @close="show = false">
      <template #title>{{ t('memories', 'Add a place') }}</template>
      <NcTextField
        v-model="search"
        :autofocus="true"
        :label="t('memories', 'Search')"
        :placeholder="t('memories', 'Search')"
      />
      <div class="place-picker">
        <span v-if="loading">{{ t('memories', 'Loading …') }}</span>
        <span v-else-if="failed">{{ t('memories', 'Failed to load places') }}</span>
        <template v-else>
          <NcButton v-for="place in candidates" :key="place.cluster_id" @click="add(place)">{{ place.name }}</NcButton>
          <span v-if="!candidates.length">{{ t('memories', 'No results found') }}</span>
        </template>
      </div>
      <template #buttons
        ><NcButton @click="close">{{ t('memories', 'Cancel') }}</NcButton></template
      >
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import Modal from '@components/modal/Modal.vue';
import { useModal } from '@services/modal';
import { config } from '@services/user-config';
import { routes } from '@services/router';
import Fuse from 'fuse.js';
import { t } from '@services/l10n';
import * as dav from '@services/dav';
import type { ICluster } from '@typings';

const route = useRoute();
const router = useRouter();
const emit = defineEmits<{ load: [] }>();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const search = ref('');
const loading = ref(false);
const failed = ref(false);
const available = computed(
  () =>
    config.places_gis > 0 &&
    (selected.value.length > 0 ||
      route.name === routes.Recognize.name ||
      route.name === routes.Base.name ||
      route.name === routes.Places.name),
);
const places = ref<ICluster[]>([]);
const selected = computed(() =>
  typeof route.query.searchPlaces === 'string'
    ? route.query.searchPlaces.split(',').filter((id) => /^-?[1-9][0-9]{0,17}$/.test(id))
    : [],
);
function label(id: string) {
  return places.value.find((place) => String(place.cluster_id) === id.replace(/^-/, ''))?.name ?? id.replace(/^-/, '');
}
const candidates = computed(() => {
  const items = places.value.filter(
    (place) => !selected.value.some((id) => id.replace(/^-/, '') === String(place.cluster_id)),
  );
  return search.value.trim()
    ? new Fuse(items, { keys: ['name'], threshold: 0.3 }).search(search.value).map((r) => r.item)
    : items;
});
let pending: Promise<void> | null = null;
async function loadPlaces() {
  if (places.value.length) return;
  if (pending) return pending;
  loading.value = true;
  failed.value = false;
  pending = (async () => {
    try {
      places.value = await dav.getPlaces({ covers: 0 });
    } catch {
      failed.value = true;
    } finally {
      loading.value = false;
      pending = null;
    }
  })();
  return pending;
}
watch(
  selected,
  async (ids) => {
    if (ids.length) await loadPlaces();
    emit('load');
  },
  { immediate: true },
);
function open() {
  search.value = '';
  show.value = true;
  void loadPlaces();
}
async function add(place: ICluster) {
  const id = String(place.cluster_id);
  if (
    !/^[1-9][0-9]{0,17}$/.test(id) ||
    selected.value.length >= 8 ||
    selected.value.some((v) => v.replace(/^-/, '') === id)
  )
    return;
  await close();
  await router.push({ path: route.path, query: { ...route.query, searchPlaces: [...selected.value, id].join(',') } });
}
async function remove(id: string) {
  const ids = selected.value.filter((value) => value !== id);
  await router.push({
    path: route.path,
    query: { ...route.query, searchPlaces: ids.length ? ids.join(',') : undefined },
  });
}
</script>

<style scoped>
.search-places {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
  padding: 4px 10px;
}
.place-picker {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 10px;
  max-height: 50vh;
  overflow-y: auto;
}
</style>
