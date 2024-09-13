<template>
  <div class="outer" v-show="years.length > 0">
    <div class="inner hide-scrollbar" ref="inner">
      <div v-for="year of years" class="group" :key="year.year" @click="click(year)">
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

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import type { IPhoto } from '@typings';

import LeftMoveIcon from 'vue-material-design-icons/ChevronLeft.vue';
import RightMoveIcon from 'vue-material-design-icons/ChevronRight.vue';

interface IYear {
  year: number;
  url: string;
  preview: IPhoto;
  photos: IPhoto[];
  text: string;
}

export default defineComponent({
  name: 'OnThisDay',
  components: {
    NcActions,
    NcActionButton,
    LeftMoveIcon,
    RightMoveIcon,
  },

  emits: {
    load: () => true,
  },

  data: () => ({
    years: [] as IYear[],
    hasRight: false,
    hasLeft: false,
    scrollStack: [] as number[],
    resizeObserver: null! as ResizeObserver,
  }),

  computed: {
    refs() {
      return this.$refs as {
        inner?: HTMLDivElement;
      };
    },
  },

  mounted() {
    const inner = this.refs.inner!;

    inner.addEventListener('scroll', this.onScroll.bind(this), {
      passive: true,
    });

    this.resizeObserver = new ResizeObserver(this.onScroll.bind(this));
    this.resizeObserver.observe(inner);

    this.refreshNow();
  },

  beforeDestroy() {
    this.resizeObserver?.disconnect();
  },

  methods: {
    onload() {
      this.$emit('load');
    },

    async refreshNow() {
      // Look for cache
      const dayIdToday = utils.dateToDayId(new Date());
      const cacheUrl = `/onthisday/${dayIdToday}`;
      const cache = await utils.getCachedData<IPhoto[]>(cacheUrl);
      if (cache) this.process(cache);

      // Network request
      const photos = await dav.getOnThisDayRaw();
      utils.cacheData(cacheUrl, photos);

      // Check if exactly same as cache
      if (cache?.length === photos.length && cache.every((p, i) => p.fileid === photos[i].fileid)) return;
      this.process(photos);
    },

    async process(photos: IPhoto[]) {
      this.years = [];

      let currentYear = 9999;
      let currentText = '';

      for (const photo of photos) {
        // Skip hidden files
        if (photo.ishidden) continue;
        if (photo.basename?.startsWith('.')) continue;

        // Skip videos for now (strange bugs)
        if (photo.isvideo) continue;

        // Get year and text for this photo
        const dateTaken = utils.dayIdToDate(photo.dayid);
        const year = dateTaken.getUTCFullYear();
        photo.key = `${photo.fileid}`;

        // DateTime calls are expensive, so check if the year
        // itself is different first, then also check the text
        if (year !== currentYear) {
          const text = utils.getFromNowStr(dateTaken);
          if (text !== currentText) {
            this.years.push({
              year,
              text,
              url: '',
              preview: null!,
              photos: [],
            });
            currentText = text;
          }
          currentYear = year;
        }

        const yearObj = this.years[this.years.length - 1];
        yearObj.photos.push(photo);
      }

      // For each year, randomly choose 10 photos to display
      for (const year of this.years) {
        year.photos = utils.randomSubarray(year.photos, 10);
      }

      // Choose preview photo
      for (const year of this.years) {
        // Try to prioritize landscape photos on desktop
        if (_m.window.innerWidth <= 600) {
          const landscape = year.photos.filter((p) => (p.w ?? 0) > (p.h ?? 0));
          year.preview = utils.randomChoice(landscape);
        }

        // Get random photo
        year.preview ||= utils.randomChoice(year.photos);
        year.url = utils.getPreviewUrl({
          photo: year.preview,
          msize: 512,
        });
      }

      await this.$nextTick();
      this.onScroll();
      this.onload();
    },

    moveLeft() {
      const inner = this.refs.inner!;
      inner.scrollBy(-(this.scrollStack.pop() || inner.clientWidth), 0);
    },

    moveRight() {
      const inner = this.refs.inner!;
      const innerRect = inner.getBoundingClientRect();
      const nextChild = Array.from(inner.children)
        .map((c) => c.getBoundingClientRect())
        .find((rect) => rect.right > innerRect.right);

      let scroll = nextChild ? nextChild.left - innerRect.left : inner.clientWidth;
      scroll = Math.min(inner.scrollWidth - inner.scrollLeft - inner.clientWidth, scroll);
      this.scrollStack.push(scroll);
      inner.scrollBy(scroll, 0);
    },

    onScroll() {
      const inner = this.refs.inner;
      if (!inner) return;
      this.hasLeft = inner.scrollLeft > 0;
      this.hasRight = inner.clientWidth + inner.scrollLeft < inner.scrollWidth - 20;
    },

    click(year: IYear) {
      const allPhotos = this.years.flatMap((y) => y.photos);
      _m.viewer.openStatic(year.preview, allPhotos, 512);
    },
  },
});
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

  :deep .dir-btn button {
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
