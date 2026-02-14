<template>
  <div class="go-to-date">
    <NcButton
      class="memories-menu-item"
      variant="tertiary-no-background"
      :title="t('memories', 'Go to date')"
      :aria-label="t('memories', 'Go to date')"
    >
      <template #icon>
        <CalendarSearchIcon :size="20" />
      </template>
    </NcButton>
    <input
      ref="dateInput"
      type="date"
      class="date-input-overlay"
      @click="constrainRange"
      @change="onDateSelected"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import CalendarSearchIcon from 'vue-material-design-icons/CalendarSearch.vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'GoToDateMenuItem',
  components: {
    NcButton,
    CalendarSearchIcon,
  },

  methods: {
    constrainRange() {
      const input = this.$refs.dateInput as HTMLInputElement;
      const event = { result: null as { min: Date; max: Date } | null };
      utils.bus.emit('memories:timeline:getDateRange', event);
      if (event.result) {
        input.min = this.toISODate(event.result.min);
        input.max = this.toISODate(event.result.max);
      }
    },

    onDateSelected(event: Event) {
      const input = event.target as HTMLInputElement;
      if (!input.value) return;

      const date = new Date(input.value + 'T00:00:00Z');
      utils.bus.emit('memories:timeline:scrollToDate', date);

      input.value = '';
    },

    toISODate(date: Date): string {
      return date.toISOString().split('T')[0];
    },
  },
});
</script>

<style lang="scss" scoped>
.go-to-date {
  position: relative;
}

.date-input-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
  z-index: 1;
}
</style>
