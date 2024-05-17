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
        :value.sync="year"
        :label="t('memories', 'Year')"
        :label-visible="true"
        :placeholder="t('memories', 'Year')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="$emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="1"
        max="12"
        :value.sync="month"
        :label="t('memories', 'Month')"
        :label-visible="true"
        :placeholder="t('memories', 'Month')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="$emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="1"
        max="31"
        :value.sync="day"
        :label="t('memories', 'Day')"
        :label-visible="true"
        :placeholder="t('memories', 'Day')"
        :disabled="disabled"
        @input="newestChange()"
        @keypress.enter="$emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="0"
        max="23"
        :value.sync="hour"
        :label="t('memories', 'Hour')"
        :label-visible="true"
        :placeholder="t('memories', 'Hour')"
        :disabled="disabled"
        @input="newestChange(true)"
        @keypress.enter="$emit('save')"
      />
      <NcTextField
        class="field"
        type="number"
        min="0"
        max="59"
        :value.sync="minute"
        :label="t('memories', 'Minute')"
        :placeholder="t('memories', 'Minute')"
        :disabled="disabled"
        @input="newestChange(true)"
        @keypress.enter="$emit('save')"
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
          :value.sync="yearLast"
          :label="t('memories', 'Year')"
          :label-visible="true"
          :placeholder="t('memories', 'Year')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="$emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="1"
          max="12"
          :value.sync="monthLast"
          :label="t('memories', 'Month')"
          :label-visible="true"
          :placeholder="t('memories', 'Month')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="$emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="1"
          max="31"
          :value.sync="dayLast"
          :label="t('memories', 'Day')"
          :label-visible="true"
          :placeholder="t('memories', 'Day')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="$emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="0"
          max="23"
          :value.sync="hourLast"
          :label="t('memories', 'Hour')"
          :label-visible="true"
          :placeholder="t('memories', 'Hour')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="$emit('save')"
        />
        <NcTextField
          class="field"
          type="number"
          min="0"
          max="59"
          :value.sync="minuteLast"
          :label="t('memories', 'Minute')"
          :placeholder="t('memories', 'Minute')"
          :disabled="disabled"
          @input="oldestChange()"
          @keypress.enter="$emit('save')"
        />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'EditDate',
  components: {
    NcTextField,
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

  emits: {
    save: () => true,
  },

  data: () => ({
    sortedPhotos: [] as IPhoto[],

    year: '0',
    month: '0',
    day: '0',
    hour: '0',
    minute: '0',
    second: '0',

    yearLast: '0',
    monthLast: '0',
    dayLast: '0',
    hourLast: '0',
    minuteLast: '0',
    secondLast: '0',

    newestDirty: false,
    oldestDirty: false,
  }),

  mounted() {
    this.init();
  },

  watch: {
    photos() {
      this.init();
    },
  },

  computed: {
    date() {
      return this.makeDate(this.year, this.month, this.day, this.hour, this.minute, this.second);
    },

    dateLast() {
      return this.makeDate(
        this.yearLast,
        this.monthLast,
        this.dayLast,
        this.hourLast,
        this.minuteLast,
        this.secondLast,
      );
    },

    dateDiff() {
      return this.date && this.dateLast ? this.date.getTime() - this.dateLast.getTime() : 0;
    },

    origDateNewest() {
      return new Date(this.sortedPhotos[0].datetaken! * 1000);
    },

    origDateOldest() {
      return new Date(this.sortedPhotos[this.sortedPhotos.length - 1].datetaken! * 1000);
    },

    origDateDiff() {
      return this.origDateNewest.getTime() - this.origDateOldest.getTime();
    },

    scaleFactor() {
      return this.origDateDiff > 0 ? this.dateDiff / this.origDateDiff : 0;
    },

    longDateStr() {
      return this.date ? utils.getLongDateStr(this.date, false, true) : this.t('memories', 'Invalid Date');
    },

    longDateStrLast() {
      return this.dateLast ? utils.getLongDateStr(this.dateLast, false, true) : this.t('memories', 'Invalid Date');
    },
  },

  methods: {
    init() {
      // Filter out only photos that have a datetaken
      const photos = (this.sortedPhotos = this.photos.filter((photo) => photo.datetaken !== undefined));

      // Sort photos by datetaken descending
      photos.sort((a, b) => b.datetaken! - a.datetaken!);

      // Get date of newest photo
      let date = new Date(photos[0].datetaken! * 1000);
      this.year = date.getUTCFullYear().toString();
      this.month = (date.getUTCMonth() + 1).toString();
      this.day = date.getUTCDate().toString();
      this.hour = date.getUTCHours().toString();
      this.minute = date.getUTCMinutes().toString();
      this.second = date.getUTCSeconds().toString();

      // Get date of oldest photo
      if (photos.length > 1) {
        date = new Date(photos[photos.length - 1].datetaken! * 1000);
        this.yearLast = date.getUTCFullYear().toString();
        this.monthLast = (date.getUTCMonth() + 1).toString();
        this.dayLast = date.getUTCDate().toString();
        this.hourLast = date.getUTCHours().toString();
        this.minuteLast = date.getUTCMinutes().toString();
        this.secondLast = date.getUTCSeconds().toString();
      }
    },

    validate() {
      if (!this.date) {
        throw new Error(this.t('memories', 'Invalid Date'));
      }

      if (this.photos.length > 1) {
        if (!this.dateLast) {
          throw new Error(this.t('memories', 'Invalid Date'));
        }

        if (this.dateDiff < -60000) {
          // 1 minute
          throw new Error(this.t('memories', 'Newest date is older than oldest date'));
        }
      }
    },

    result(photo: IPhoto): undefined | string {
      if (!this.oldestDirty && !this.newestDirty) {
        return undefined;
      }

      if (this.sortedPhotos.length === 0 || !this.date) {
        return undefined;
      }

      if (this.sortedPhotos.length === 1) {
        return utils.getExifDateStr(this.date);
      }

      // Interpolate date
      const dT = this.date.getTime();
      const doT = this.origDateNewest.getTime();
      const offset = ((photo.datetaken ?? 0) * 1000 || doT) - doT;
      return utils.getExifDateStr(new Date(dT + offset * this.scaleFactor));
    },

    newestChange(time = false) {
      if (this.sortedPhotos.length === 0 || !this.date) {
        return;
      }

      this.newestDirty = true;

      // Set the last date to have the same offset to newest date
      try {
        const dateNew = this.date;
        const offset = dateNew.getTime() - this.origDateNewest.getTime();
        const dateLastNew = new Date(this.origDateOldest.getTime() + offset);

        this.yearLast = dateLastNew.getUTCFullYear().toString();
        this.monthLast = (dateLastNew.getUTCMonth() + 1).toString();
        this.dayLast = dateLastNew.getUTCDate().toString();

        if (time) {
          this.hourLast = dateLastNew.getUTCHours().toString();
          this.minuteLast = dateLastNew.getUTCMinutes().toString();
          this.secondLast = dateLastNew.getUTCSeconds().toString();
        }
      } catch (error) {}
    },

    oldestChange() {
      this.oldestDirty = true;
    },

    makeDate(yearS: string, monthS: string, dayS: string, hourS: string, minuteS: string, secondS: string) {
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
    },
  },
});
</script>

<style scoped lang="scss">
.fields {
  .field {
    width: 4.1em;
    display: inline-block;
    max-width: calc(20% - 4px);
  }

  :deep label {
    font-size: 0.8em;
    padding: 0 !important;
    padding-left: 3px !important;
  }
}

.title-text {
  font-size: 0.9em;
  margin-left: 0.2em;
}

.oldest {
  margin-top: 10px;
}
</style>
