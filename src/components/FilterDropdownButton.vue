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
          :initial-filters="currentFilters"
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
import { bus } from '@services/utils';

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

  data: () => ({
    currentFilters: {
      minRating: 0,
      tags: [],
      embeddedTags: [],
    } as IFilters,
  }),

  mounted() {
    bus.on('memories:filters:changed', this.onFiltersChangedFromBus);
  },

  beforeUnmount() {
    bus.off('memories:filters:changed', this.onFiltersChangedFromBus);
  },

  computed: {
    hasActiveFilters() {
      const filters = this.currentFilters;
      return filters.minRating > 0 || filters.tags.length > 0 || filters.embeddedTags.length > 0;
    },
  },

  methods: {
    onFiltersChangedFromBus(filters: IFilters) {
      this.currentFilters = { ...filters };
    },

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

    // Match header button colors when inside header and not active
    header#header &:not(.active) {
      color: var(--color-background-plain-text, var(--color-primary-text)) !important;
    }

    &.active {
      opacity: 1;
      color: var(--color-primary);
      background-color: var(--color-primary-element-light);

      &:hover {
        opacity: 1;
        background-color: var(--color-primary-element-hover);
      }
    }
  }
}
</style>
