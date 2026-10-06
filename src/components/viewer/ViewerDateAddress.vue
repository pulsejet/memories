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

<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';

import * as utils from '@services/utils';
import * as nativex from '@native';
import { isPartiallyInViewport } from '@services/common';
import { useRouteIsBase } from '@services/route-checker';

import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue';

import type { IPhoto } from '@typings';

defineOptions({
  name: 'ViewerDateAddress',
});

const props = withDefaults(
  defineProps<{
    photo?: IPhoto | null;
    twoLines?: boolean;
  }>(),
  {
    photo: null,
    twoLines: false,
  },
);

const router = useRouter();
const routeIsBase = useRouteIsBase();

const dateStr = computed((): string | null => {
  const date = props.photo?.imageInfo?.datetaken;
  if (!date) return null;
  return utils.getDateStr(new Date(date * 1000));
});

const timeStr = computed((): string | null => {
  const date = props.photo?.imageInfo?.datetaken;
  if (!date) return null;
  return utils.getTimeStr(new Date(date * 1000));
});

const dateTaken = computed((): string | null => {
  const date = props.photo?.imageInfo?.datetaken;
  if (!date) return null;
  return utils.getLongDateStr(new Date(date * 1000), false, true);
});

const addressShort = computed((): string | null => {
  return props.photo?.imageInfo?.address_short ?? null;
});

const dayTo = computed(() => {
  if (!props.photo?.imageInfo?.intimeline) return undefined;

  // We need the real dayid to jump to anywhere in the timeline,
  // even if the current view is a month view. Note that this means
  // the target view cannot be a month view :/
  const dayid = props.photo.dayid_real ?? props.photo.dayid;

  // We use the fileid, not the key. The key may not be the same as the
  // timeline's fileid, for example on faces where the key is the faceid.
  const fileid = props.photo.fileid;

  // Both parameters are required for jumping.
  if (!dayid || !fileid) return undefined;

  return {
    name: 'timeline',
    hash: `#${utils.fragment.types.day}/${dayid}/${fileid}`,
  };
});

async function jumpToTimeline() {
  if (!dayTo.value) return;
  beep();

  // If we are already on the timeline, just close the viewer.
  // This way we don't unnecessarily accumulate history entries.
  if (routeIsBase.value) {
    await utils.fragment.pop(utils.fragment.types.viewer);

    // Check if the image is alraedy in the viewport, and scroll only if not.
    // This is to avoid the annoying "jump" when closing the viewer.
    const photoEl = document.querySelector<HTMLDivElement>(`.p-outer--${props.photo?.key}`);
    if (!photoEl || !isPartiallyInViewport(photoEl)) {
      await router.replace(dayTo.value);
    }
    return;
  }

  // On another route - push an entry in navigation so that pressing
  // back will bring us back to an open viewer at the same spot.
  try {
    await router.push(dayTo.value);
  } catch {
    // e.g. duplicated navigation; nothing to do
  }
}

function beep() {
  nativex.playTouchSound();
}
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
