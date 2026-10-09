<template>
  <div class="outer" v-show="isReady && isContent">
    <div class="inner hide-scrollbar" ref="inner">
      <div v-for="year of years" class="group" :key="year.text" @click="click(year)">
        <XImgFade class="fill-block" :src="year.url" duration="700ms" />

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
import { nextTick, computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { cacheData, getCachedData } from '@services/cache';
import { t } from '@services/l10n';
import { config } from '@services/user-config';
import { useRouteState } from '@services/route-state';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

import LeftMoveIcon from 'vue-material-design-icons/ChevronLeft.vue';
import RightMoveIcon from 'vue-material-design-icons/ChevronRight.vue';
import XImgFade from '@components/frame/XImgFade.vue';

import type { IPhoto } from '@typings';

interface IYear {
  year: number;
  url: string;
  preview: IPhoto;
  photos: IPhoto[];
  text: string;
}

const emit = defineEmits<{
  load: [];
}>();

const inner = useTemplateRef<HTMLDivElement>('inner');

const years = ref<IYear[] | null>(null);
const hasRight = ref(false);
const hasLeft = ref(false);
const scrollStack = ref<number[]>([]);
let resizeObserver: ResizeObserver | null = null;
let requestId = 0;

useRouteState({ state: { years }, scroll: { inner } });

const isReady = computed(() => years.value !== null);
const isContent = computed(() => !!years.value?.length);

onMounted(() => {
  inner.value!.addEventListener('scroll', onScroll, { passive: true });
  resizeObserver = new ResizeObserver(onScroll);
  resizeObserver.observe(inner.value!);
  void refresh();
});

onBeforeUnmount(() => {
  requestId++;
  resizeObserver?.disconnect();
});

async function refresh(): Promise<void> {
  const myRequestId = ++requestId;

  try {
    // Look for cache
    const dayIdToday = utils.dateToDayId(new Date());
    const cacheUrl = `/onthisday/${dayIdToday}`;
    const cache = await getCachedData<IPhoto[]>(cacheUrl);
    if (myRequestId !== requestId) return;
    utils.applyAuids(cache);
    if (cache) {
      years.value = process(cache);
      void onLoad();
    }
    if (myRequestId !== requestId) return;

    // Network request
    const photos = await dav.getOnThisDayRaw();
    if (myRequestId !== requestId) return;
    utils.applyAuids(photos);
    cacheData(cacheUrl, photos);

    // Check if exactly same as cache
    if (cache?.length === photos.length && cache.every((p, i) => p.fileid === photos[i].fileid)) return;
    years.value = process(photos);
    void onLoad();
  } finally {
    if (myRequestId === requestId) {
      years.value ??= [];
    }
  }
}

function process(photos: IPhoto[]): IYear[] {
  const list: IYear[] = [];

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
        list.push({
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

    const yearObj = list[list.length - 1];
    yearObj.photos.push(photo);
  }

  // For each year, randomly choose 10 photos to display
  for (const year of list) {
    year.photos = utils.randomSubarray(year.photos, config.onthisday_photos_per_year);
  }

  // Choose preview photo
  for (const year of list) {
    year.preview ||= utils.randomChoice(year.photos);
    year.url = utils.getPreviewUrl({
      photo: year.preview,
      msize: 512,
    });
  }

  return list;
}

async function onLoad(): Promise<void> {
  emit('load');
  await nextTick();
  onScroll();
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
  if (!inner.value) return;
  hasLeft.value = inner.value.scrollLeft > 0;
  hasRight.value = inner.value.clientWidth + inner.value.scrollLeft < inner.value.scrollWidth - 20;
}

function click(year: IYear) {
  const allPhotos = years.value?.flatMap((y) => y.photos) ?? [];
  _m.viewer.openStatic(year.preview, allPhotos, 512);
}

defineExpose({ isReady, isContent, refresh });
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

  :deep(img) {
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
