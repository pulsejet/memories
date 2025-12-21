<template>
  <div class="outer">
    <!-- Single photo editing -->
    <div v-if="photos.length === 1">
      <EmbeddedTagSelector
        ref="embeddedTagSelector"
        :value="tagSelection"
        @update:value="tagSelection = $event"
        :disabled="disabled"
        :multiple="true"
        :placeholder="t('memories', 'Select tags...')"
        :show-full-path="true"
      />
    </div>

    <!-- Multiple photos editing -->
    <EditEmbeddedTagsMulti
      v-else
      ref="editEmbeddedTagsMulti"
      :photos="photos"
      :disabled="disabled"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import EmbeddedTagSelector from '@components/EmbeddedTagSelector.vue';
import EditEmbeddedTagsMulti from './EditEmbeddedTagsMulti.vue';

import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'EditEmbeddedTags',
  components: {
    EmbeddedTagSelector,
    EditEmbeddedTagsMulti,
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

  data: () => ({
    tagSelection: [] as string[],
  }),

  mounted() {
    this.init();
  },

  methods: {
    init() {
      // Only init for single photo (multi-photo component handles its own init)
      if (this.photos.length === 1) {
        const photo = this.photos[0];
        const exif = photo.imageInfo?.exif;
        
        if (exif) {
          const tags = utils.getTagsFromExif(exif);
          this.tagSelection = tags.map(tagPath => tagPath.join('/'));
        }
      }
    },

    result() {
      if (this.photos.length === 1) {
        return this.resultSingle();
      } else {
        return this.resultMulti();
      }
    },

    resultSingle() {
      // Single photo: simple replace
      const photo = this.photos[0];
      const exif = photo.imageInfo?.exif;
      const originalTags = exif ? utils.getTagsFromExif(exif).map(t => t.join('/')) : [];
      
      // Check if changed
      if (JSON.stringify(this.tagSelection.sort()) === JSON.stringify(originalTags.sort())) {
        return null;
      }

      return this.tagsToExifFields(this.tagSelection);
    },

    resultMulti() {
      const multiComponent = this.$refs.editEmbeddedTagsMulti as any;
      const operation = multiComponent?.result?.();
      
      if (!operation) {
        return null;
      }

      return {
        multiPhotoOperation: operation,
      };
    },

    tagsToExifFields(tags: string[]) {
      // If tags are being cleared, set undefined for all fields
      if (tags.length === 0) {
        return {
          Keywords: undefined,
          Subject: undefined,
          TagsList: undefined,
          HierarchicalSubject: undefined,
        };
      }

      // Return the tags in all four EXIF fields for maximum compatibility
      const tagsList = tags.map(tag => tag.replace(/\|/g, '/'));
      const hierarchicalSubject = tags.map(tag => tag.replace(/\//g, '|'));
      const keywords = tags.map(tag => tag.replace(/\|/g, '/'));
      const subject = tags.map(tag => {
        const parts = tag.split(/[\/|]/);
        return parts[parts.length - 1];
      });

      return {
        Keywords: keywords,
        Subject: subject,
        TagsList: tagsList,
        HierarchicalSubject: hierarchicalSubject,
      };
    },
  },
});
</script>

<style scoped lang="scss">
.outer {
  margin-top: 10px;

  :deep(.embedded-tag-selector) {
    width: 100%;
  }
}
</style>

