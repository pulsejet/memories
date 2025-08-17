<template>
  <div class="head-row no-user-select" :class="{ selected: item.selected }" :style="{ height: `${item.size}px` }">
    <div class="super" v-if="item.super !== undefined">
      {{ item.super }}
    </div>
    <div class="main" @click="click">
      <CheckCircle v-once :size="20" class="select" />
      <span class="name"> {{ name }}</span>
    </div>
    
    <!-- Filter Button with Popover -->
    <div class="filter-container">
      <NcPopover popup-role="dialog">
        <template #trigger>
          <NcButton
            type="tertiary-no-background"
            title="Filter photos"
            :aria-label="t('memories', 'Filter photos')"
            class="filter-button"
          >
            <template #icon>
              <FilterIcon :size="20" />
            </template>
          </NcButton>
        </template>
        <template #default>
          <FilterComponent
            :initial-filters="currentFilters"
            @filter-change="onFilterChange"
            @apply-filters="onApplyFilters"
          />
        </template>
      </NcPopover>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import type { IHeadRow } from '@typings';

import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';
import FilterIcon from 'vue-material-design-icons/FilterMenu.vue';
import FilterComponent from '../FilterComponent.vue';

export default defineComponent({
  name: 'RowHead',

  components: {
    CheckCircle,
    FilterIcon,
    NcButton,
    NcPopover,
    FilterComponent,
  },

  props: {
    item: {
      type: Object as PropType<IHeadRow>,
      required: true,
    },
  },

  emits: {
    click: (item: IHeadRow) => true,
    'filter-change': (filters: any) => true,
  },

  data() {
    return {
      currentFilters: {
        minRating: 0,
        tags: [],
      },
    };
  },

  computed: {
    name() {
      return utils.getHeadRowName(this.item);
    },

    hasActiveFilters() {
      return this.currentFilters.minRating > 0 || this.currentFilters.tags.length > 0;
    },
  },

  methods: {
    click() {
      this.$emit('click', this.item);
    },

    onFilterChange(filters: any) {
      this.currentFilters = { ...filters };
      this.$emit('filter-change', filters);
    },

    t(app: string, text: string, vars?: any) {
      return t('memories', text, vars);
    },
  },
});
</script>

<style lang="scss" scoped>
.head-row {
  contain: strict;
  padding-top: 10px;
  padding-left: 3px;
  font-size: 0.9em;
  position: relative;
  display: flex;
  align-items: flex-start;

  > div {
    position: relative;
    &.super {
      font-size: 1.4em;
      font-weight: bold;
      margin-bottom: 4px;
    }
    &.main {
      display: inline-block;
      font-weight: 500;
    }
  }

  .select {
    position: absolute;
    left: 0;
    top: 50%;
    display: none;
    opacity: 0;
    transform: translateY(-45%);
    transition: opacity 0.2s ease;
    border-radius: 50%;
    cursor: pointer;
  }
  .name {
    display: block;
    transition: transform 0.2s ease;
    cursor: pointer;
    font-size: 1.075em;
  }

  .filter-container {
    z-index: 1000;
    margin-left: 24px;

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

  @mixin visible {
    .select {
      display: flex;
      opacity: 0.7;
    }
    .name {
      transform: translateX(24px);
    }
  }

  // Show the icon (gray) when hovering or selected
  @media (hover: hover) and (pointer: fine) {
    &:hover {
      @include visible;
    }
  }

  // Show the icon (blue) when selected
  &.selected {
    @include visible;
    .select {
      opacity: 1;
      color: var(--color-primary);
    }
  }

  @media (max-width: 768px) {
    transform: translateX(8px);
  }
}
</style>
