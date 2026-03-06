<template>
  <NcDateTimePicker
    :value="new Date()"
    type="date"
    :clearable="false"
    :placeholder="t('memories', 'Go to date')"
    @update:value="onDateSelected"
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

  methods: {
    onDateSelected(date: Date) {
      if (!date) return;
      utils.bus.emit('memories:timeline:scrollToDate', date);
    },
  },
});
</script>
