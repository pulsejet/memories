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
        :value.sync="exif[field.field]"
        :placeholder="placeholder(field)"
        @input="dirty[field.field] = true"
        trailing-button-icon="close"
        :show-trailing-button="dirty[field.field]"
        @trailing-button-click="reset(field)"
        @keypress.enter="$emit('save')"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import { translate as t } from '@services/l10n';

import type { IExif, IPhoto } from '@typings';

interface IField {
  field: keyof IExif;
  label: string;
}

export default defineComponent({
  components: {
    NcTextField,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    save: () => true,
  },

  data: () => ({
    exif: null as Record<keyof IExif, string> | null,
    dirty: {} as Record<keyof IExif, boolean>,

    fields: [
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
    ] as IField[],
  }),

  mounted() {
    const exif = {} as NonNullable<typeof this.exif>;

    for (const field of this.fields) {
      this.reset(field, exif);
    }

    this.exif = exif;
  },

  methods: {
    result() {
      const diff = {} as Record<keyof IExif, string>;
      for (const field of this.fields) {
        if (this.dirty[field.field]) {
          diff[field.field] = this.exif![field.field];
        }
      }
      return diff;
    },

    label(field: IField) {
      return field.label + (this.dirty[field.field] ? '*' : '');
    },

    placeholder(field: IField) {
      return this.dirty[field.field] ? t('memories', 'Empty') : t('memories', 'Unchanged');
    },

    reset(field: IField, exif: typeof this.exif = null) {
      this.dirty[field.field] = false;

      // We use this to pass an object during initialization
      exif ??= this.exif!;

      // Check if all photos have the same value for this field
      const first = this.photos[0]?.imageInfo?.exif?.[field.field];
      if (this.photos.every((p) => p.imageInfo?.exif?.[field.field] === first)) {
        exif[field.field] = String(first ?? String());
      } else {
        exif[field.field] = String();
      }
    },
  },
});
</script>

<style scoped lang="scss">
.fields {
  .field {
    margin-top: 0;
    margin-bottom: 8px;
  }
  :deep label {
    font-size: 0.9em;
    padding: 0 !important;
    padding-left: 5px !important;
  }
}
</style>
