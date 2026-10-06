<template>
  <!-- Mobile top bar, two lines DATE,TIME+ADDR -->
  <template v-if="twoLines">
    <div
      v-if="dateStr && !routeIsPublic"
      class="date-line"
      :class="{ 'is-link': dayTo }"
      :title="t('memories', 'Show in timeline')"
      :aria-label="t('memories', 'Show in timeline')"
      @click.stop="jumpToTimeline"
    >
      {{ dateStr }} <ChevronRightIcon v-if="dayTo" class="chev" :size="20" />
    </div>
    <div class="time-line" v-if="timeStr">
      {{ timeStr }}<template v-if="addressShort"> &bull; {{ addressShort }}</template>
    </div>
  </template>

  <!-- Desktop bottom bar, one line DATE+TIME+ADDR -->
  <template v-else>
    <div
      v-if="dateTaken && !routeIsPublic"
      class="exif date"
      :class="{ 'is-link': dayTo }"
      :title="t('memories', 'Show in timeline')"
      :aria-label="t('memories', 'Show in timeline')"
      @click.stop="jumpToTimeline"
    >
      {{ dateTaken }}<template v-if="addressShort"> &bull; {{ addressShort }}</template>
      <ChevronRightIcon v-if="dayTo" class="chev" :size="18" />
    </div>
  </template>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import * as utils from '@services/utils';
import * as nativex from '@native';

import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'ViewerDateAddress',

  components: {
    ChevronRightIcon,
  },

  props: {
    photo: {
      type: Object as PropType<IPhoto | null>,
      default: null,
    },
    twoLines: {
      type: Boolean,
      default: false,
    },
  },

  computed: {
    dateStr(): string | null {
      const date = this.photo?.imageInfo?.datetaken;
      if (!date) return null;
      return utils.getDateStr(new Date(date * 1000));
    },

    timeStr(): string | null {
      const date = this.photo?.imageInfo?.datetaken;
      if (!date) return null;
      return utils.getTimeStr(new Date(date * 1000));
    },

    dateTaken(): string | null {
      const date = this.photo?.imageInfo?.datetaken;
      if (!date) return null;
      return utils.getLongDateStr(new Date(date * 1000), false, true);
    },

    addressShort(): string | null {
      return this.photo?.imageInfo?.address_short ?? null;
    },

    dayTo() {
      if (!this.photo?.imageInfo?.intimeline) return undefined;

      // We need the real dayid to jump to anywhere in the timeline,
      // even if the current view is a month view. Note that this means
      // the target view cannot be a month view :/
      const dayid = this.photo.dayid_real ?? this.photo.dayid;

      // We use the fileid, not the key. The key may not be the same as the
      // timeline's fileid, for example on faces where the key is the faceid.
      const fileid = this.photo.fileid;

      // Both parameters are required for jumping.
      if (!dayid || !fileid) return undefined;

      return {
        name: 'timeline',
        hash: `#${utils.fragment.types.day}/${dayid}/${fileid}`,
      };
    },
  },

  methods: {
    async jumpToTimeline() {
      if (!this.dayTo) return;
      this.beep();

      // If we are already on the timeline, just close the viewer.
      // This way we don't unnecessarily accumulate history entries.
      if (this.routeIsBase) {
        await utils.fragment.pop(utils.fragment.types.viewer);

        // Check if the image is alraedy in the viewport, and scroll only if not.
        // This is to avoid the annoying "jump" when closing the viewer.
        const photoEl = document.querySelector<HTMLDivElement>(`.p-outer--${this.photo?.key}`);
        if (!photoEl || !utils.isPartiallyInViewport(photoEl)) {
          await this.$router.replace(this.dayTo);
        }
        return;
      }

      // On another route - push an entry in navigation so that pressing
      // back will bring us back to an open viewer at the same spot.
      try {
        await this.$router.push(this.dayTo);
      } catch {
        // e.g. duplicated navigation; nothing to do
      }
    },

    beep() {
      nativex.playTouchSound();
    },
  },
});
</script>

<style lang="scss" scoped>
.date-line {
  font-size: 1em;
  font-weight: 500;
  position: relative;
  display: inline-block;

  // Chevron pokes out right without affecting centering.
  :deep(.chev) {
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
  }
}
.time-line {
  font-size: 0.85em;
  opacity: 0.85;
  max-width: calc(100vw - 220px);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.date {
  @media (max-width: 768px) {
    display: none;
  }

  :deep(.chev) {
    display: inline-block;
    vertical-align: middle;
    height: 20px;
  }
}

.is-link {
  cursor: pointer;
  pointer-events: auto;
}
</style>
