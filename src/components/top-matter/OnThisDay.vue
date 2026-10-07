<template>
  <div class="outer" v-show="years.length > 0">
    <div class="inner hide-scrollbar" ref="inner">
      <div v-for="year of years" class="group" :key="year.text" @click="click(year)">
        <XImg class="fill-block" :src="year.url" />

        <div class="overlay top-left fill-block">
          {{ year.text }}
        </div>
      </div>
    </div>

    <div class="left-btn dir-btn" v-if="hasLeft">
      <NcActions>
        <NcActionButton :aria-label="t('memories', 'Move left')" @click="moveLeft">
          {{ t('memories', 'Move left') }}
          <template #icon> <LeftMoveIcon v-once :size="28" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
    <div class="right-btn dir-btn" v-if="hasRight">
      <NcActions>
        <NcActionButton :aria-label="t('memories', 'Move right')" @click="moveRight">
          {{ t('memories', 'Move right') }}
          <template #icon> <RightMoveIcon v-once :size="28" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import * as utils from '@services/utils';
import { cacheData, getCachedData } from '@services/cache';
import * as dav from '@services/dav';
import { t } from '@services/l10n';
import { config } from '@services/user-config';
import type { IPhoto } from '@typings';

import LeftMoveIcon from 'vue-material-design-icons/ChevronLeft.vue';
import RightMoveIcon from 'vue-material-design-icons/ChevronRight.vue';
import XImg from '@components/frame/XImg.vue';

interface IYear {
  year: number;
  url: string;
  preview: IPhoto;
  photos: IPhoto[];
  text: string;
}

defineOptions({
  name: 'OnThisDay',
});

const emit = defineEmits<{
  load: [];
}>();

const inner = useTemplateRef<HTMLDivElement>('inner');

const years = ref<IYear[]>([]);
const hasRight = ref(false);
const hasLeft = ref(false);
const scrollStack = ref<number[]>([]);
let resizeObserver: ResizeObserver | null = null;

const photosPerYear = computed((): number => {
  return config.onthisday_photos_per_year;
});

onMounted(() => {
  const innerVal = inner.value!;

  innerVal.addEventListener('scroll', onScroll, {
    passive: true,
  });

  resizeObserver = new ResizeObserver(onScroll);
  resizeObserver.observe(innerVal);

  refreshNow();
});

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
});

function onload() {
  emit('load');
}

async function refreshNow() {
  // Look for cache
  const dayIdToday = utils.dateToDayId(new Date());
  const cacheUrl = `/onthisday/${dayIdToday}`;
  const cache = await getCachedData<IPhoto[]>(cacheUrl);
  utils.applyAuids(cache);
  if (cache) process(cache);

  // Network request
  const photos = await dav.getOnThisDayRaw();
  utils.applyAuids(photos);
  cacheData(cacheUrl, photos);

  // Check if exactly same as cache
  if (cache?.length === photos.length && cache.every((p, i) => p.fileid === photos[i].fileid)) return;
  process(photos);
}

async function process(photos: IPhoto[]) {
  years.value = [];

  let currentText = '';
  let prevDayId = Number.MAX_SAFE_INTEGER;

  for (const photo of photos) {
    // Skip hidden files
    if (!photo.dayid) continue;
    if (photo.ishidden) continue;
    if (photo.basename?.startsWith('.')) continue;

    photo.key = `${photo.fileid}`;

    // New anniversary, not calendar year (breaks at year boundary).
    // Mirrors Timeline.vue. DateTime calls are expensive.
    if (Math.abs(prevDayId - photo.dayid) > 30) {
      const dateTaken = utils.dayIdToDate(photo.dayid);
      const year = dateTaken.getUTCFullYear();
      const text = utils.getFromNowStr(dateTaken, { padding: 10 });
      if (text !== currentText) {
        years.value.push({
          year,
          text,
          url: '',
          preview: null!,
          photos: [],
        });
        currentText = text;
      }
    }
    prevDayId = photo.dayid;

    const yearObj = years.value[years.value.length - 1];
    yearObj.photos.push(photo);
  }

  // For each year, randomly choose 10 photos to display
  for (const year of years.value) {
    year.photos = utils.randomSubarray(year.photos, photosPerYear.value);
  }

  // Choose preview photo
  for (const year of years.value) {
    year.preview ||= utils.randomChoice(year.photos);
    year.url = utils.getPreviewUrl({
      photo: year.preview,
      msize: 512,
    });
  }

  await nextTick();
  onScroll();
  onload();
}

function moveLeft() {
  const innerVal = inner.value!;
  innerVal.scrollBy(-(scrollStack.value.pop() ?? innerVal.clientWidth), 0);
}

function moveRight() {
  const innerVal = inner.value!;
  const innerRect = innerVal.getBoundingClientRect();
  const nextChild = Array.from(innerVal.children)
    .map((c) => c.getBoundingClientRect())
    .find((rect) => rect.right > innerRect.right);

  let scroll = nextChild ? nextChild.left - innerRect.left : innerVal.clientWidth;
  scroll = Math.min(innerVal.scrollWidth - innerVal.scrollLeft - innerVal.clientWidth, scroll);
  scrollStack.value.push(scroll);
  innerVal.scrollBy(scroll, 0);
}

function onScroll() {
  const innerVal = inner.value;
  if (!innerVal) return;
  hasLeft.value = innerVal.scrollLeft > 0;
  hasRight.value = innerVal.clientWidth + innerVal.scrollLeft < innerVal.scrollWidth - 20;
}

function click(year: IYear) {
  const allPhotos = years.value.flatMap((y) => y.photos);
  _m.viewer.openStatic(year.preview, allPhotos, 512);
}
</script>

<style lang="scss" scoped>
$height: 200px;
$mobHeight: 165px;

.outer {
  width: calc(100% - 50px);
  height: $height;
  overflow: hidden;
  position: relative;
  padding: 0 calc(28px * 0.6);

  // Sloppy: ideally this should be done in Timeline
  // to put a gap between the title and this
  margin-top: 10px;

  .inner {
    height: 100%;
    white-space: nowrap;
    overflow-x: scroll;
    overflow-y: hidden;
    scroll-behavior: smooth;
    border-radius: 10px;
    will-change: scroll-position;
  }

  :deep(.dir-btn button) {
    transform: scale(0.6);
    box-shadow: var(--color-main-text) 0 0 3px 0 !important;
    background-color: var(--color-main-background) !important;
  }

  .left-btn {
    position: absolute;
    top: 50%;
    left: 0;
    transform: translate(-10%, -50%);
  }

  .right-btn {
    position: absolute;
    top: 50%;
    right: 0;
    transform: translate(10%, -50%);
  }

  @media (max-width: 768px) {
    width: 100%;
    padding: 0;
    .inner {
      padding: 0 8px;
      border-radius: 0;
    }
    .dir-btn {
      display: none;
    }
  }
  @media (max-width: 600px) {
    height: $mobHeight;
  }
}

.group {
  height: $height;
  aspect-ratio: 4/3;
  display: inline-block;
  position: relative;
  cursor: pointer;

  &:not(:last-of-type) {
    margin-right: 8px;
  }

  img {
    cursor: inherit;
    object-fit: cover;
    border-radius: 10px;
    background-color: var(--color-background-dark);
    background-clip: padding-box, content-box;
  }

  .overlay {
    background-color: rgba(0, 0, 0, 0.2);
    border-radius: 10px;
    display: flex;
    align-items: end;
    justify-content: center;
    color: white;
    font-size: 1.2em;
    padding: 5%;
    white-space: normal;
    cursor: inherit;
    transition: background-color 0.2s ease-in-out;
  }

  &:hover .overlay {
    background-color: transparent;
  }

  @media (max-width: 600px) {
    aspect-ratio: 3/4;
    height: $mobHeight;
    .overlay {
      font-size: 1.1em;
    }
  }
}
</style>
