<template>
  <div class="fields" v-if="exif">
    <div v-for="field of fields" :key="field.field">
      <label :for="'exif-field-' + field.field">
        {{ label(field) }}
      </label>
      <NcTextField
        class="field"
        :id="'exif-field-' + field.field"
        :label-outside="true"
        :value.sync="exif[field.field]"
        :placeholder="placeholder(field)"
        @input="dirty[field.field] = true"
        trailing-button-icon="close"
        :show-trailing-button="dirty[field.field]"
        @trailing-button-click="reset(field)"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { IPhoto } from '../../types';

const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');

import { translate as t } from '@nextcloud/l10n';

export default defineComponent({
  components: {
    NcTextField,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
  },

  data: () => ({
    exif: null as any,
    dirty: {},

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
    ],
  }),

  mounted() {
    let exif = {};
    for (const field of this.fields) {
      exif[field.field] = null;
      this.dirty[field.field] = false;
    }

    const photos = this.photos as IPhoto[];
    for (const photo of photos) {
      if (!photo.imageInfo?.exif) {
        continue;
      }

      for (const field of this.fields) {
        const ePhoto = photo.imageInfo?.exif[field.field];
        const eCurr = exif[field.field];
        if (ePhoto && (eCurr === null || ePhoto === eCurr)) {
          exif[field.field] = String(ePhoto);
        } else {
          exif[field.field] = '';
        }
      }
    }

    this.exif = exif;
  },

  methods: {
    result() {
      const diff = {};
      for (const field of this.fields) {
        if (this.dirty[field.field]) {
          diff[field.field] = this.exif[field.field];
        }
      }
      return diff;
    },

    label(field: any) {
      return field.label + (this.dirty[field.field] ? '*' : '');
    },

    placeholder(field: any) {
      return this.dirty[field.field] ? t('memories', 'Empty') : t('memories', 'Unchanged');
    },

    reset(field: any) {
      this.exif[field.field] = '';
      this.dirty[field.field] = false;
    },
  },
});
</script>

<style scoped lang="scss">
.fields {
  .field {
    margin-bottom: 8px;
  }
  :deep label {
    font-size: 0.8em;
    padding: 0 !important;
    padding-left: 5px !important;
  }
}
</style>
