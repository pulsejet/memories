<template>
  <div v-if="selected.length" class="search-places">
    <span>{{ t('memories', 'Place filters') }}</span>
    <NcButton
      v-for="id in selected"
      :key="id"
      :aria-label="t('memories', 'Remove {name} from filter', { name: label(id) })"
      @click="remove(id)"
    >
      {{ id.startsWith('-') ? '−' : '+' }} {{ label(id) }} &times;
    </NcButton>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import { t } from '@services/l10n';
import * as dav from '@services/dav';
import type { ICluster } from '@typings';

const route = useRoute();
const router = useRouter();
const emit = defineEmits<{ load: [] }>();
const places = ref<ICluster[]>([]);
const selected = computed(() =>
  typeof route.query.searchPlaces === 'string'
    ? route.query.searchPlaces.split(',').filter((id) => /^-?[1-9][0-9]{0,17}$/.test(id))
    : [],
);
function label(id: string) {
  return places.value.find((place) => String(place.cluster_id) === id.replace(/^-/, ''))?.name ?? id.replace(/^-/, '');
}
watch(
  selected,
  async (ids) => {
    if (ids.length && !places.value.length) {
      try {
        places.value = await dav.getPlaces({ covers: 0 });
      } catch {
        /* IDs remain visible if labels cannot load. */
      }
    }
    emit('load');
  },
  { immediate: true },
);
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
</style>
