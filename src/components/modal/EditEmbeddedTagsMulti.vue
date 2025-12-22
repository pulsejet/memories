<template>
  <div class="outer">
    <div class="mode-selector">
      <NcButton 
        :type="mode === 'add' ? 'primary' : 'secondary'"
        class="mode-button"
        :class="{ active: mode === 'add' }"
        @click="mode = 'add'"
      >
        {{ addTagsLabel }}
      </NcButton>
      <NcButton 
        :type="mode === 'remove' ? 'primary' : 'secondary'"
        class="mode-button"
        :class="{ active: mode === 'remove' }"
        @click="mode = 'remove'"
      >
        {{ removeTagsLabel }}
      </NcButton>
      <NcButton 
        :type="mode === 'override' ? 'primary' : 'secondary'"
        class="mode-button"
        :class="{ active: mode === 'override' }"
        @click="mode = 'override'"
      >
        {{ setTagsLabel }}
      </NcButton>
    </div>

    <div class="mode-description">
      <span v-if="mode === 'add'">
        {{ addDescription }}
      </span>
      <span v-else-if="mode === 'remove'">
        {{ removeDescription }}
      </span>
      <span v-else-if="mode === 'override'">
        {{ overrideDescription }}
      </span>
    </div>

    <div v-if="commonTags.length > 0" class="common-tags-info">
      <InfoIcon :size="16" />
      <span>{{ commonTagsDescription }}</span>
    </div>

    <EmbeddedTagSelector
      ref="embeddedTagSelector"
      :value="tagSelection"
      @update:value="tagSelection = $event"
      :disabled="disabled"
      :multiple="true"
      :input-label="inputLabel"
      :placeholder="selectTagsPlaceholder"
      :show-full-path="true"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import InfoIcon from 'vue-material-design-icons/Information.vue';

import EmbeddedTagSelector from '@components/EmbeddedTagSelector.vue';

import * as utils from '@services/utils';
import { translate as t } from '@services/l10n';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'EditEmbeddedTagsMulti',
  components: {
    NcButton,
    InfoIcon,
    EmbeddedTagSelector,
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
    mode: 'add' as 'add' | 'remove' | 'override',
    tagSelection: [] as string[],
    commonTags: [] as string[],
    hasUserModifiedSelection: false,
  }),

  computed: {
    photoCount(): number {
      return this.photos.length;
    },

    addDescription(): string {
      return t('memories', 'Add these tags to all {n} selected photos', { n: this.photoCount });
    },

    removeDescription(): string {
      return t('memories', 'Remove these tags from all {n} selected photos', { n: this.photoCount });
    },

    overrideDescription(): string {
      return t('memories', 'Replace all tags in {n} selected photos with these tags', { n: this.photoCount });
    },

    commonTagsDescription(): string {
      return t('memories', 'Common tags: {tags}', { tags: this.commonTags.join(', ') });
    },

    inputLabel(): string {
      if (this.mode === 'add') {
        return t('memories', 'Tags to add');
      } else if (this.mode === 'remove') {
        return t('memories', 'Tags to remove');
      } else {
        return t('memories', 'New tags');
      }
    },

    selectTagsPlaceholder(): string {
      return t('memories', 'Select tags...');
    },

    addTagsLabel(): string {
      return t('memories', 'Add Tags');
    },

    removeTagsLabel(): string {
      return t('memories', 'Remove Tags');
    },

    setTagsLabel(): string {
      return t('memories', 'Set Tags');
    },
  },

  watch: {
    mode(newMode) {
      // When switching to override mode, if user hasn't modified selection yet,
      // pre-populate with common tags
      if (newMode === 'override' && !this.hasUserModifiedSelection && this.tagSelection.length === 0) {
        this.tagSelection = [...this.commonTags];
      }
    },

    tagSelection() {
      // Mark that user has modified the selection
      this.hasUserModifiedSelection = true;
    },
  },

  mounted() {
    this.findCommonTags();
  },

  methods: {
    findCommonTags() {
      // Find tags that are common to ALL photos
      let commonTagsSet: Set<string> | null = null;
      
      for (const photo of this.photos) {
        const exif = photo.imageInfo?.exif;
        const tags = exif ? utils.getTagsFromExif(exif) : [];
        const tagStrings = new Set(tags.map(tagPath => tagPath.join('/')));
        
        if (commonTagsSet === null) {
          commonTagsSet = tagStrings;
        } else {
          commonTagsSet = new Set([...commonTagsSet].filter(t => tagStrings.has(t)));
        }
      }
      
      this.commonTags = commonTagsSet ? [...commonTagsSet].sort() : [];
    },

    result() {
      // Return the operation to perform
      if (this.tagSelection.length === 0) {
        return null; // No operation
      }

      return {
        mode: this.mode,
        tags: this.tagSelection,
      };
    },
  },
});
</script>

<style scoped lang="scss">
.outer {
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  gap: 12px;

  :deep(.embedded-tag-selector) {
    width: 100%;
  }
}

.mode-selector {
  display: flex;
  gap: 8px;

  .mode-button {
    flex: 1;
  }
}

.mode-description {
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
  padding: 8px 12px;
  background-color: var(--color-background-hover);
  border-radius: var(--border-radius);
}

.common-tags-info {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
  padding: 6px 10px;
  background-color: var(--color-primary-element-light);
  border-radius: var(--border-radius);
  border-left: 3px solid var(--color-primary-element);
}
</style>

