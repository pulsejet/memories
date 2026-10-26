<template>
  <div class="people-filter">
    <NcButton :disabled="selected.length >= 8" @click="open">
      {{ t('memories', 'Also with ...') }}
    </NcButton>
    <span v-if="selected.length">{{ t('memories', 'Photos containing all selected people') }}</span>
    <NcButton
      v-for="id in selected"
      :key="id"
      :aria-label="t('memories', 'Remove {name} from filter', { name: label(id) })"
      @click="remove(id)"
    >
      {{ label(id) }} &times;
    </NcButton>
    <Modal v-if="show" ref="modal" size="large" @close="show = false">
      <template #title>{{ t('memories', 'Also with ...') }}</template>
      <FaceList @select="select" />
      <template #buttons>
        <NcButton @click="close">{{ t('memories', 'Cancel') }}</NcButton>
      </template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import FaceList from '@components/modal/FaceList.vue';
import Modal from '@components/modal/Modal.vue';
import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { showError } from '@services/utils/dialog';
import * as dav from '@services/dav';
import type { IFace } from '@typings';

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const faces = ref<IFace[]>([]);
const selected = computed(() => {
  const value = route.query.recognizePeople;
  return typeof value === 'string' ? [...new Set(value.split(',').filter((id) => /^[1-9][0-9]{0,17}$/.test(id)))] : [];
});

function label(id: string) {
  return faces.value.find((face) => String(face.cluster_id) === id)?.name || t('memories', 'Unnamed person');
}

async function loadFaces() {
  try {
    faces.value = await dav.getFaceList('recognize');
  } catch {
    showError(t('memories', 'Failed to load faces'));
  }
}

watch(
  () => route.path,
  () => {
    faces.value = [];
    if (selected.value.length) void loadFaces();
  },
  { immediate: true },
);

function open() {
  show.value = true;
  if (!faces.value.length) void loadFaces();
}

async function select(face: IFace) {
  const id = String(face.cluster_id);
  if (!/^[1-9][0-9]{0,17}$/.test(id) || selected.value.length >= 8) return;
  await close();
  await update([...new Set([...selected.value, id])]);
}

async function remove(id: string) {
  await update(selected.value.filter((value) => value !== id));
}

async function update(ids: string[]) {
  await router.push({
    path: route.path,
    query: { ...route.query, recognizePeople: ids.length ? ids.join(',') : undefined },
  });
}
</script>

<style scoped>
.people-filter {
  flex-basis: 100%;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
}
</style>
