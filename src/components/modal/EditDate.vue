<template>
  <div>
    <div class="title-text">
      <span v-if="photos.length > 1"> [{{ t('memories', 'Newest') }}] </span>
      {{ longDateStr }}
      {{ newestDirty ? '*' : '' }}
    </div>

    <div class="fields">
      <NcTextField
        class="field"
        type="number"
        min="0"
        max="5000"
        v-model="year"
        :label="t('memories', 'Year')"
        :label-visible="true"
        :placeholder="t('memories', 'Year')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="1"
        max="12"
        v-model="month"
        :label="t('memories', 'Month')"
        :label-visible="true"
        :placeholder="t('memories', 'Month')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="1"
        max="31"
        v-model="day"
        :label="t('memories', 'Day')"
        :label-visible="true"
        :placeholder="t('memories', 'Day')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="0"
        max="23"
        v-model="hour"
        :label="t('memories', 'Hour')"
        :label-visible="true"
        :placeholder="t('memories', 'Hour')"
        :disabled="disabled"
        @input="newestChange(true)"
        @keypress.enter="emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="0"
        max="59"
        v-model="minute"
        :label="t('memories', 'Minute')"
        :placeholder="t('memories', 'Minute')"
        :disabled="disabled"
        @input="newestChange(true)"
        @keypress.enter="emit('save')"
      />
    </div>

    <div v-if="photos.length > 1" class="oldest">
      <div class="title-text">
        <span> [{{ t('memories', 'Oldest') }}] </span>
        {{ longDateStrLast }}
        {{ oldestDirty ? '*' : '' }}
      </div>

      <div class="fields">
        <NcTextField
          class="field"
          type="number"
          min="0"
          max="5000"
          v-model="yearLast"
          :label="t('memories', 'Year')"
          :label-visible="true"
          :placeholder="t('memories', 'Year')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="1"
          max="12"
          v-model="monthLast"
          :label="t('memories', 'Month')"
          :label-visible="true"
          :placeholder="t('memories', 'Month')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="1"
          max="31"
          v-model="dayLast"
          :label="t('memories', 'Day')"
          :label-visible="true"
          :placeholder="t('memories', 'Day')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="0"
          max="23"
          v-model="hourLast"
          :label="t('memories', 'Hour')"
          :label-visible="true"
          :placeholder="t('memories', 'Hour')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="0"
          max="59"
          v-model="minuteLast"
          :label="t('memories', 'Minute')"
          :placeholder="t('memories', 'Minute')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="emit('save')"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue';

import NcTextField from '@nextcloud/vue/components/NcTextField';

import { t } from '@services/l10n';
import * as utils from '@services/utils/common';

import type { IPhoto } from '@typings';

defineOptions({
  name: 'EditDate',
});

const props = defineProps<{
  photos: IPhoto[];
  disabled?: boolean;
}>();

const emit = defineEmits<{
  (e: 'save'): void;
}>();

const sortedPhotos = ref<IPhoto[]>([]);

const year = ref('0');
const month = ref('0');
const day = ref('0');
const hour = ref('0');
const minute = ref('0');
const second = ref('0');

const yearLast = ref('0');
const monthLast = ref('0');
const dayLast = ref('0');
const hourLast = ref('0');
const minuteLast = ref('0');
const secondLast = ref('0');

const newestDirty = ref(false);
const oldestDirty = ref(false);

const date = computed(() => makeDate(year.value, month.value, day.value, hour.value, minute.value, second.value));

const dateLast = computed(() =>
  makeDate(yearLast.value, monthLast.value, dayLast.value, hourLast.value, minuteLast.value, secondLast.value),
);

const dateDiff = computed(() => (date.value && dateLast.value ? date.value.getTime() - dateLast.value.getTime() : 0));

const origDateNewest = computed(() => new Date(sortedPhotos.value[0].datetaken! * 1000));
const origDateOldest = computed(() => new Date(sortedPhotos.value.at(-1)!.datetaken! * 1000));
const origDateDiff = computed(() => origDateNewest.value.getTime() - origDateOldest.value.getTime());
const scaleFactor = computed(() => (origDateDiff.value > 0 ? dateDiff.value / origDateDiff.value : 0));

const longDateStr = computed(() =>
  date.value ? utils.getLongDateStr(date.value, false, true) : t('memories', 'Invalid Date'),
);

const longDateStrLast = computed(() =>
  dateLast.value ? utils.getLongDateStr(dateLast.value, false, true) : t('memories', 'Invalid Date'),
);

onMounted(() => {
  init();
});

watch(
  () => props.photos,
  () => {
    init();
  },
);

function init() {
  // Filter out only photos that have a datetaken
  const photos = (sortedPhotos.value = props.photos.filter((photo) => photo.datetaken !== undefined));

  // Sort photos by datetaken descending
  photos.sort((a, b) => b.datetaken! - a.datetaken!);

  // Get date of newest photo
  let date = new Date(photos[0].datetaken! * 1000);
  year.value = date.getUTCFullYear().toString();
  month.value = (date.getUTCMonth() + 1).toString();
  day.value = date.getUTCDate().toString();
  hour.value = date.getUTCHours().toString();
  minute.value = date.getUTCMinutes().toString();
  second.value = date.getUTCSeconds().toString();

  // Get date of oldest photo
  if (photos.length > 1) {
    date = new Date(photos.at(-1)!.datetaken! * 1000);
    yearLast.value = date.getUTCFullYear().toString();
    monthLast.value = (date.getUTCMonth() + 1).toString();
    dayLast.value = date.getUTCDate().toString();
    hourLast.value = date.getUTCHours().toString();
    minuteLast.value = date.getUTCMinutes().toString();
    secondLast.value = date.getUTCSeconds().toString();
  }
}

function validate() {
  if (!date.value) {
    throw new Error(t('memories', 'Invalid Date'));
  }

  if (props.photos.length > 1) {
    if (!dateLast.value) {
      throw new Error(t('memories', 'Invalid Date'));
    }

    if (dateDiff.value < -60000) {
      // 1 minute
      throw new Error(t('memories', 'Newest date is older than oldest date'));
    }
  }
}

function result(photo: IPhoto): undefined | string {
  if (!oldestDirty.value && !newestDirty.value) {
    return undefined;
  }

  if (sortedPhotos.value.length === 0 || !date.value) {
    return undefined;
  }

  if (sortedPhotos.value.length === 1) {
    return utils.getExifDateStr(date.value);
  }

  // Interpolate date
  const dT = date.value.getTime();
  const doT = origDateNewest.value.getTime();
  const offset = ((photo.datetaken ?? 0) * 1000 || doT) - doT;
  return utils.getExifDateStr(new Date(dT + offset * scaleFactor.value));
}

function newestChange(time = false) {
  if (sortedPhotos.value.length === 0 || !date.value) {
    return;
  }

  newestDirty.value = true;

  // Set the last date to have the same offset to newest date
  try {
    const dateNew = date.value;
    const offset = dateNew.getTime() - origDateNewest.value.getTime();
    const dateLastNew = new Date(origDateOldest.value.getTime() + offset);

    yearLast.value = dateLastNew.getUTCFullYear().toString();
    monthLast.value = (dateLastNew.getUTCMonth() + 1).toString();
    dayLast.value = dateLastNew.getUTCDate().toString();

    if (time) {
      hourLast.value = dateLastNew.getUTCHours().toString();
      minuteLast.value = dateLastNew.getUTCMinutes().toString();
      secondLast.value = dateLastNew.getUTCSeconds().toString();
    }
  } catch (error) {}
}

function oldestChange() {
  oldestDirty.value = true;
}

function makeDate(yearS: string, monthS: string, dayS: string, hourS: string, minuteS: string, secondS: string) {
  const year = parseInt(yearS, 10);
  const month = parseInt(monthS, 10) - 1;
  const day = parseInt(dayS, 10);
  const hour = parseInt(hourS, 10);
  const minute = parseInt(minuteS, 10);
  let second = parseInt(secondS, 10) || 0; // needs validation

  if (isNaN(year)) return null;
  if (isNaN(month)) return null;
  if (isNaN(day)) return null;
  if (isNaN(hour)) return null;
  if (isNaN(minute)) return null;
  if (isNaN(second)) return null;

  // Validate date
  if (year < 0 || year > 5000) return null;
  if (month < 0 || month > 11) return null;

  // Number of days in month
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  if (day < 1 || day > daysInMonth) return null;

  // Validate time
  if (hour < 0 || hour > 23) return null;
  if (minute < 0 || minute > 59) return null;
  if (second < 0 || second > 59) second = 0;

  return new Date(Date.UTC(year, month, day, hour, minute, second));
}

defineExpose({ validate, result });
</script>

<style scoped lang="scss">
.fields {
  .field {
    width: 4.1em;
    display: inline-block;
    max-width: calc(20% - 4px);
  }

  :deep(label) {
    font-size: 0.8em;
    padding: 0 !important;
    padding-left: 3px !important;
  }
}

.title-text {
  font-size: 0.9em;
  margin-left: 0.2em;
  margin-bottom: 4px;
}

.oldest {
  margin-top: 10px;
}
</style>
