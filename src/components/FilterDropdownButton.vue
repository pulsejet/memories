<template>
  <div class="filter-container">
    <NcPopover popup-role="dialog">
      <template #trigger>
        <NcButton
          type="tertiary-no-background"
          title="Filter photos"
          :aria-label="t('memories', 'Filter photos')"
          class="filter-button"
          :class="{ active: hasActiveFilters }"
        >
          <template #icon>
            <FilterIcon :size="20" />
          </template>
        </NcButton>
      </template>
      <template #default>
        <FilterComponent
          :disabled="disabled"
          :initial-filters="initialFilters"
          @filter-change="onFilterChange"
        />
      </template>
    </NcPopover>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js';
import { translate as t } from '@services/l10n';

import type { IFilters } from '@typings';

import FilterIcon from 'vue-material-design-icons/FilterMenu.vue';
import FilterComponent from './FilterComponent.vue';

export default defineComponent({
  name: 'FilterDropdownButton',

  components: {
    FilterIcon,
    NcButton,
    NcPopover,
    FilterComponent,
  },

  props: {
    disabled: {
      type: Boolean,
      default: false,
    },
    initialFilters: {
      type: Object as PropType<IFilters>,
      default: () => ({
        minRating: 0,
        tags: [],
        embeddedTags: [],
      } as IFilters),
    },
  },

  emits: {
    'filter-change': (filters: IFilters) => true,
  },

  computed: {
    hasActiveFilters() {
      const filters = this.initialFilters;
      return filters.minRating > 0 || filters.tags.length > 0 || filters.embeddedTags.length > 0;
    },
  },

  methods: {
    onFilterChange(filters: IFilters) {
      this.$emit('filter-change', filters);
    },

    t,
  },
});
</script>

<style lang="scss" scoped>
.filter-container {
  z-index: 1000;

  .filter-button {
    opacity: 0.7;
    transition: opacity 0.2s ease;

    &:hover {
      opacity: 1;
    }

    &.active {
      opacity: 1;
      color: var(--color-primary);
      background-color: var(--color-primary-element-light);
    }
  }
}
</style>
