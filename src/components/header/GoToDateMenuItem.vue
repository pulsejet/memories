<template>
  <NcDateTimePicker
    v-model="selectedDate"
    type="date"
    :clearable="false"
    :placeholder="t('memories', 'Go to date')"
    @change="onDateSelected"
  />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcDateTimePicker from '@nextcloud/vue/dist/Components/NcDateTimePicker.js';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'GoToDateMenuItem',
  components: {
    NcDateTimePicker,
  },

  data() {
    return {
      selectedDate: new Date(),
    };
  },

  methods: {
    onDateSelected(date: Date) {
      if (!date) return;
      utils.bus.emit('memories:timeline:scrollToDate', date);
    },
  },
});
</script>
