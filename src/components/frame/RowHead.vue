<template>
  <div class="head-row no-user-select" :class="{ selected: item.selected }" :style="{ height: `${item.size}px` }">
    <div class="super" v-if="item.super !== undefined">
      {{ item.super }}
    </div>
    <div class="main" @click="click">
      <CheckCircle v-once :size="20" class="select" />
      <span class="name"> {{ name }} </span>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';
import { IHeadRow } from '../../types';

import CheckCircle from 'vue-material-design-icons/CheckCircle.vue';

import * as utils from '../../services/utils';

export default defineComponent({
  name: 'RowHead',

  components: {
    CheckCircle,
  },

  props: {
    item: {
      type: Object as PropType<IHeadRow>,
      required: true,
    },
    monthView: {
      type: Boolean,
      required: true,
    },
  },

  emits: {
    click: (item: IHeadRow) => true,
  },

  computed: {
    name() {
      // Check cache
      if (this.item.name) {
        return this.item.name;
      }

      // Make date string
      // The reason this function is separate from processDays is
      // because this call is terribly slow even on desktop
      const dateTaken = utils.dayIdToDate(this.item.dayId);
      let name: string;
      if (this.monthView) {
        name = utils.getMonthDateStr(dateTaken);
      } else {
        name = utils.getLongDateStr(dateTaken, true);
      }

      // Cache and return
      this.item.name = name;
      return name;
    },
  },

  methods: {
    click() {
      this.$emit('click', this.item);
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
