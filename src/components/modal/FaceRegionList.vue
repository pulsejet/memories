<template>
  <div class="regions">
    <div class="section-title">{{ t('memories', 'Searched areas') }}</div>
    <ul>
      <li
        v-for="region in regions"
        :key="region.id"
        @mouseenter="$emit('highlight', region.id)"
        @mouseleave="$emit('highlight', null)"
        @focusin="$emit('highlight', region.id)"
        @focusout="$emit('highlight', null)"
      >
        <span class="text">{{ regionText(region) }}</span>
        <NcButton
          :variant="confirming === region.id ? 'error' : 'tertiary'"
          :aria-label="removeLabel(region)"
          :title="removeLabel(region)"
          :disabled="removing"
          @click="remove(region)"
        >
          <template #icon>
            <DeleteIcon :size="18" />
          </template>
          <template v-if="confirming === region.id">{{ t('memories', 'Really remove') }}</template>
        </NcButton>
      </li>
    </ul>
    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';

import DeleteIcon from 'vue-material-design-icons/Delete.vue';

import { t } from '@services/l10n';
import { faceRecognitionDeleteRegions, type IManualRegion } from '@services/dav/face';

import { errorText, regionText } from './faceMarking';

/**
 * The areas of the photo drawn to be searched, with what came of each, and
 * removing one of them. The pointer on an entry shows its area on the photo.
 */
defineOptions({
  name: 'FaceRegionList',
});

defineProps<{
  regions: IManualRegion[];
}>();

const emit = defineEmits<{
  /** The area to show on the photo, or none */
  highlight: [regionId: number | null];
  /** An area was removed; the text says so, for the user */
  done: [message: string];
}>();

/** The area whose remove button was clicked once, and waits for the second click */
const confirming = ref<number | null>(null);
const removing = ref(false);
const error = ref('');

function removeLabel(region: IManualRegion): string {
  return region.state === 'pending'
    ? t('memories', 'Remove this area; it is not searched then')
    : t('memories', 'Remove this area; the faces found in it stay');
}

/** Removes an area, on the second click: the first one asks. */
async function remove(region: IManualRegion): Promise<void> {
  if (removing.value) return;
  if (confirming.value !== region.id) {
    confirming.value = region.id;
    return;
  }
  removing.value = true;
  error.value = '';
  try {
    await faceRecognitionDeleteRegions([region.id]);
    emit('highlight', null);
    emit(
      'done',
      region.state === 'pending'
        ? t('memories', 'Area removed. It is not searched.')
        : t('memories', 'Area removed. The faces found in it stay.'),
    );
  } catch (e) {
    console.error(e);
    error.value = errorText(e, t('memories', 'The area could not be removed.'));
  } finally {
    removing.value = false;
    confirming.value = null;
  }
}
</script>

<style lang="scss" scoped>
.regions {
  font-size: 0.9em;

  .section-title {
    font-weight: bold;
    margin-bottom: 2px;
  }

  ul {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  li {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 0 4px;
    border-radius: var(--border-radius, 4px);

    &:hover,
    &:focus-within {
      background: var(--color-background-hover);
    }

    .text {
      flex: 1;
    }
  }
}
</style>
