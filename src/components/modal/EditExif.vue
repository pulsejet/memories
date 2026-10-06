<template>
  <div class="fields" v-if="exif">
    <div v-for="field of fields" :key="field.field">
      <label :for="`exif-field-${field.field}`">
        {{ label(field) }}
      </label>
      <NcTextField
        class="field"
        :id="`exif-field-${field.field}`"
        :disabled="disabled"
        :label-outside="true"
        v-model="exif[field.field]"
        :placeholder="placeholder(field)"
        @input="dirty[field.field] = true"
        trailing-button-icon="close"
        :show-trailing-button="dirty[field.field]"
        @trailing-button-click="reset(field)"
        @keypress.enter="emit('save')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, defineAsyncComponent } from 'vue';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import { translate as t } from '@services/l10n';

import type { IExif, IPhoto } from '@typings';

interface IField {
  field: keyof IExif;
  label: string;
}

const props = defineProps<{
  photos: IPhoto[];
  disabled?: boolean;
}>();

const emit = defineEmits<{
  (e: 'save'): void;
}>();

const exif = ref<Record<keyof IExif, string> | null>(null);
const dirty = ref({} as Record<keyof IExif, boolean>);

const fields = ref([
  {
    field: 'Title',
    label: t('memories', 'Title'),
  },
  {
    field: 'Description',
    label: t('memories', 'Description'),
  },
  {
    field: 'Label',
    label: t('memories', 'Label'),
  },
  {
    field: 'Make',
    label: t('memories', 'Camera Make'),
  },
  {
    field: 'Model',
    label: t('memories', 'Camera Model'),
  },
  {
    field: 'LensModel',
    label: t('memories', 'Lens Model'),
  },
  {
    field: 'Copyright',
    label: t('memories', 'Copyright'),
  },
] as IField[]);

onMounted(() => {
  const exifInit = {} as NonNullable<typeof exif.value>;

  for (const field of fields.value) {
    reset(field, exifInit);
  }

  exif.value = exifInit;
});

function result() {
  const diff = {} as Record<keyof IExif, string>;
  for (const field of fields.value) {
    if (dirty.value[field.field]) {
      diff[field.field] = exif.value![field.field];
    }
  }
  return diff;
}

function label(field: IField) {
  return field.label + (dirty.value[field.field] ? '*' : '');
}

function placeholder(field: IField) {
  return dirty.value[field.field] ? t('memories', 'Empty') : t('memories', 'Unchanged');
}

function reset(field: IField, exifIn: typeof exif.value = null) {
  dirty.value[field.field] = false;

  // We use this to pass an object during initialization
  exifIn ??= exif.value!;

  // Check if all photos have the same value for this field
  const first = props.photos[0]?.imageInfo?.exif?.[field.field];
  if (props.photos.every((p) => p.imageInfo?.exif?.[field.field] === first)) {
    exifIn[field.field] = String(first ?? String());
  } else {
    exifIn[field.field] = String();
  }
}

defineExpose({ result });
</script>

<style scoped lang="scss">
.fields {
  .field {
    margin-top: 0;
    margin-bottom: 8px;
  }
  :deep(label) {
    font-size: 0.9em;
    padding: 0 !important;
    padding-left: 5px !important;
  }
}
</style>
