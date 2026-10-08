<template>
  <div class="outer">
    <NcSelectTags
      ref="selectTags"
      class="nc-component"
      v-model="tagSelection"
      :label-outside="true"
      :disabled="disabled"
      :limit="null"
      :options-filter="tagFilter"
      :get-option-label="tagLabel"
      :create-option="createOption"
      :taggable="true"
      @option:created="handleCreate"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, useTemplateRef, onMounted, defineAsyncComponent } from 'vue';

const NcSelectTags = defineAsyncComponent(() => import('@nextcloud/vue/components/NcSelectTags'));

import { t } from '@services/l10n';
import * as dav from '@services/dav';

import type { IPhoto } from '@typings';

defineOptions({
  name: 'EditTags',
});

const props = defineProps<{
  photos: IPhoto[];
  disabled?: boolean;
}>();

const selectTags = useTemplateRef<{ availableTags: dav.ITag[] }>('selectTags');

const origIds = ref(new Set<number>());
const tagSelection = ref<number[]>([]);
const newTags = ref(new Map<number, dav.ITag>());

onMounted(() => {
  init();
});

function init() {
  let tagIds: number[] | null = null;

  // Find common tags in all selected photos
  for (const photo of props.photos) {
    const s = new Set<number>();
    for (const tag of Object.keys(photo.imageInfo?.tags ?? {}).map(Number)) {
      s.add(tag);
    }
    tagIds = tagIds ? [...tagIds].filter((x: number) => s.has(x)) : [...s];
  }

  tagSelection.value = tagIds ?? [];
  origIds.value = new Set(tagSelection.value);
}

function tagFilter(element: dav.ITag, index: number) {
  return element.displayName !== '' && element.canAssign && element.userAssignable && element.userVisible;
}

function tagLabel({ displayName }: dav.ITag) {
  return t('recognize', displayName);
}

function createOption(newDisplayName: string): dav.ITag {
  // do not create tags that already exist
  const existing = getAvailable().find((x) => x.displayName.toLocaleLowerCase() === newDisplayName.toLocaleLowerCase());
  if (existing) {
    return existing;
  }

  // placeholder tag
  return {
    userVisible: true,
    userAssignable: true,
    canAssign: true,
    displayName: newDisplayName,
    id: Math.random(),
  };
}

function getAvailable(): dav.ITag[] {
  // FIXME: this is extremely fragile
  return selectTags.value!.availableTags;
}

function handleCreate(newTag: dav.ITag) {
  getAvailable().push(newTag);

  // Keep the new tags around, but only create them when the user clicks save
  // This way we don't create tags that are never used
  newTags.value.set(newTag.id, newTag);
}

async function result() {
  const add = tagSelection.value.filter((x) => !origIds.value.has(x));
  const remove = [...origIds.value].filter((x) => !tagSelection.value.includes(x));

  // Return null here so there is no useless query
  if (add.length === 0 && remove.length === 0) {
    return null;
  }

  // Create new tags if necessary
  const tagsToAdd = add.map((x) => newTags.value.get(x)).filter((x) => x) as dav.ITag[];
  if (tagsToAdd.length > 0) {
    await Promise.all(
      tagsToAdd.map(async (x: dav.ITag) => {
        // create the actual tag to get the final ID
        const tag = await dav.createTag(x);

        // replace the temporary tag ID with the real one
        const i = add.findIndex((y) => y === x.id);
        add[i] = tag.id;
      }),
    );
  }

  return { add, remove };
}

defineExpose({ result });
</script>

<style scoped lang="scss">
.outer {
  .nc-component {
    width: 100%;
    :deep(.vs__dropdown-toggle) {
      padding-block: 0 !important;
      padding-inline: 0 !important;
    }
  }
}
</style>
