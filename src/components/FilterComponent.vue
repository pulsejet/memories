<template>
  <div class="filter-component">
    <form @submit.prevent>
      <!-- Minimum Rating Filter -->
      <div class="filter-section">
        <label class="filter-label">
          {{ t('memories', 'Minimum Rating') }}
        </label>
        <div class="rating-filter">
          <RatingStars 
            :rating="filters.minRating"
            :size="20"
            @update:rating="onRatingChange"
          />
          <NcButton 
            v-if="filters.minRating > 0"
            type="tertiary-no-background"
            :aria-label="t('memories', 'Clear rating filter')"
            @click="clearRating"
          >
            <template #icon>
              <CloseIcon :size="16" />
            </template>
          </NcButton>
        </div>
      </div>

      <!-- Tags Filter -->
      <div class="filter-section">
        <label class="filter-label">
          {{ t('memories', 'Filter by Tags') }}
        </label>
        <NcSelectTags
          ref="selectTags"
          v-model="filters.tags"
          class="tags-filter"
          :label-outside="false"
          :disabled="disabled"
          :limit="null"
          :options-filter="tagFilter"
          :get-option-label="tagLabel"
          :placeholder="t('memories', 'Select tags...')"
          @update:value="onTagsChange"
        />
      </div>

      <!-- Filter Actions -->
      <div class="filter-actions">
        <NcButton
          type="secondary"
          @click="clearAllFilters"
          :disabled="!hasActiveFilters"
        >
          {{ t('memories', 'Clear All') }}
        </NcButton>
      </div>
    </form>
  </div>
</template>

<script lang="ts">
import type { IFilters } from '@typings';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcSelectTags from '@nextcloud/vue/dist/Components/NcSelectTags.js';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import RatingStars from './RatingStars.vue';
import { defineComponent, type PropType } from 'vue';

export default defineComponent({
  name: 'FilterComponent',
  
  components: {
    NcButton,
    NcSelectTags,
    RatingStars,
    CloseIcon,
  },

  props: {
    /** Whether the component is disabled */
    disabled: {
      type: Boolean,
      default: false,
    },
    /** Initial filter values */
    initialFilters: {
      type: Object as PropType<IFilters>,
      default: () => ({
        minRating: 0,
        tags: []
      } as IFilters),
    },
  },

  emits: ['filter-change'],

  data() {
    return {
      filters: {
        minRating: this.initialFilters.minRating || 0,
        tags: this.initialFilters.tags || [],
      } as IFilters,
    };
  },

  computed: {
    hasActiveFilters() {
      return this.filters.minRating > 0 || this.filters.tags.length > 0;
    },
  },

  watch: {
    initialFilters: {
      handler(newFilters) {
        this.filters = {
          minRating: newFilters.minRating || 0,
          tags: newFilters.tags || [],
        };
      },
      deep: true,
      immediate: true,
    },
  },

  methods: {
    emitFilterChange() {
      utils.bus.emit('memories:filters:changed', { ...this.filters });
      this.$emit('filter-change', { ...this.filters });
    },

    onRatingChange(rating: number) {
      this.filters.minRating = rating;
      (this as any).emitFilterChange();
    },

    onTagsChange(tags: string[]) {
      this.filters.tags = tags;
      (this as any).emitFilterChange();
    },

    clearRating() {
      this.filters.minRating = 0;
      (this as any).emitFilterChange();
    },

    clearAllFilters() {
      this.filters = {
        minRating: 0,
        tags: [],
      };
      (this as any).emitFilterChange();
    },

    tagFilter(element: any) {
      return element.displayName !== '' && element.canAssign && element.userAssignable && element.userVisible;
    },

    tagLabel({ displayName }: { displayName: string }) {
      return displayName;
    },

    t(app: string, text: string, vars: Record<string, string>) {
      return t('memories', text, vars);
    },
  },
});
</script>

<style lang="scss" scoped>
.filter-component {
  padding: 16px;
  min-width: 300px;
  max-width: 400px;

  form {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
}

.filter-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.filter-label {
  font-weight: 600;
  font-size: 14px;
  color: var(--color-text-darker);
}

.rating-filter {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tags-filter {
  width: 100%;

  :deep ul {
    max-height: 150px;
  }
}

.filter-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style> 