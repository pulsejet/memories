<template>
  <div class="p-outer-super">
    <div
      class="p-outer fill-block"
      :class="{
        selected: data.flag & c.FLAG_SELECTED,
        placeholder: data.flag & c.FLAG_PLACEHOLDER,
        leaving: data.flag & c.FLAG_LEAVING,
        error: data.flag & c.FLAG_LOAD_FAIL,
      }"
    >
      <div
        class="select"
        v-once
        v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
        @pointerdown.passive="$emit('select', $event)"
      >
        <CheckCircleIcon :size="18" />
      </div>
      
      <!-- Interactive rating overlay -->
      <div class="interactive-rating" v-if="showInteractiveRating">
        <RatingStars 
          :rating="currentRating"
          :size="14"
          @update:rating="updateRating"
        />
      </div>

      <div class="flag top-right">
        <RawIcon class="raw" v-if="isRaw" :size="28" />
        <div class="video" v-if="data.flag & c.FLAG_IS_VIDEO">
          <span class="time" v-if="data.video_duration">{{ videoDuration }}</span>
          <VideoIcon :size="22" />
        </div>
        <div
          class="livephoto"
          v-if="data.liveid"
          @mouseenter.passive="playVideo"
          @mouseleave.passive="stopVideo"
          @touchstart.passive="touchVideo"
        >
          <LivePhotoIcon :size="22" :spin="liveState.waiting" :playing="liveState.playing" />
        </div>
      </div>

      <div class="flag bottom-right">
        <StarIcon :size="22" v-if="data.flag & c.FLAG_IS_FAVORITE" />
        <LocalIcon :size="22" v-if="data.flag & c.FLAG_IS_LOCAL" />
      </div>

      <div class="flag bottom-left">
        <span class="shared-by" v-if="showOwnerName && sharedBy">{{ sharedBy }}</span>
      </div>

      <div
        class="img-outer fill-block"
        :class="{ 'memories-livephoto': data.liveid }"
        @contextmenu="contextmenu"
        @pointerdown.passive="$emit('pointerdown', $event)"
        @touchstart.passive="$emit('touchstart', $event)"
        @touchmove="$emit('touchmove', $event)"
        @touchend.passive="$emit('touchend', $event)"
        @touchcancel.passive="$emit('touchend', $event)"
      >
        <XImg
          v-if="src"
          ref="ximg"
          draggable="false"
          class="ximg fill-block no-user-select"
          :class="[`memories-thumb-${data.key}`]"
          :src="src"
          :key="data.fileid"
          @load="load"
          @error="error"
        />
        <video
          ref="video"
          v-if="videoUrl"
          :src="videoUrl"
          preload="none"
          muted
          playsinline
          disableRemotePlayback
        ></video>
        <div class="overlay top-left fill-block"></div>
      </div>
      
      <!-- Metadata overlay -->
      <div class="metadata-overlay" v-if="showMetadataOverlay">
        <RatingTags 
          v-if="showRatingTags"
          :rating="currentRating"
          :tags="currentTags"
          :compact="true"
          :max-tags="3"
          :hide-stars="config.enable_exif_photo_rating_in_gallery"
        />
      </div>
      
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import * as utils from '@services/utils';
import staticConfig from '@services/static-config';
import UserConfig from '@mixins/UserConfig';
import axios from '@nextcloud/axios';
import { API } from '@services/API';
import { showError } from '@nextcloud/dialogs';

import RatingTags from '@components/RatingTags.vue';
import RatingStars from '@components/RatingStars.vue';
import LivePhotoIcon from '@components/icons/LivePhoto.vue';
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import VideoIcon from 'vue-material-design-icons/PlayCircleOutline.vue';
import LocalIcon from 'vue-material-design-icons/CloudOff.vue';
import RawIcon from 'vue-material-design-icons/Raw.vue';

import type { IDay, IPhoto } from '@typings';
import type XImg from '@components/XImg.vue';

import errorsvg from '@assets/error.svg';

export default defineComponent({
  name: 'Photo',
  
  mixins: [UserConfig],
  
  components: {
    RatingTags,
    RatingStars,
    LivePhotoIcon,
    CheckCircleIcon,
    VideoIcon,
    StarIcon,
    LocalIcon,
    RawIcon,
  },

  props: {
    data: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
    day: {
      type: Object as PropType<IDay>,
      required: true,
    },
  },

  emits: {
    select: (e: PointerEvent) => true,
    pointerdown: (e: PointerEvent) => true,
    touchstart: (e: TouchEvent) => true,
    touchmove: (e: TouchEvent) => true,
    touchend: (e: TouchEvent) => true,
  },

  data: () => ({
    touchTimer: 0,
    liveState: {
      playTimer: 0,
      playing: false,
      waiting: false,
      requested: false,
    },
    faceSrc: null as string | null,
  }),

  watch: {
    data(newData: IPhoto, oldData: IPhoto) {
      // Copy flags relevant to this component
      if (oldData && newData) {
        newData.flag |= oldData.flag & (this.c.FLAG_SELECTED | this.c.FLAG_LOAD_FAIL);
      }
    },
  },

  mounted() {
    this.faceSrc = null;

    // Setup video hooks
    const video = this.refs.video;
    if (video) {
      utils.setupLivePhotoHooks(video, this.liveState);
    }
  },

  /** Clear timers */
  beforeDestroy() {
    clearTimeout(this.touchTimer);
    clearTimeout(this.liveState.playTimer);

    // Clean up blob url if face rect was created
    if (this.faceSrc) {
      URL.revokeObjectURL(this.faceSrc);
    }
  },

  computed: {
    refs() {
      return this.$refs as {
        ximg?: InstanceType<typeof XImg> & { $el: HTMLImageElement };
        video?: HTMLVideoElement;
      };
    },

    videoDuration(): string | null {
      if (this.data.video_duration) {
        return utils.getDurationStr(this.data.video_duration);
      }
      return null;
    },

    videoUrl(): string | null {
      if (this.data.liveid) {
        return utils.getLivePhotoVideoUrl(this.data, true);
      }
      return null;
    },

    src(): string | null {
      this.data.etag; // dependency

      if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
        return null;
      } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
        return errorsvg;
      } else if (this.faceSrc) {
        return this.faceSrc;
      } else {
        return this.url();
      }
    },

    isRaw(): boolean {
      return !!this.data.stackraw || this.data.mimetype === this.c.MIME_RAW;
    },

    showOwnerName(): boolean {
      if (this.routeIsBase && !staticConfig.getSync('show_owner_name_timeline')) {
        return false;
      }
      return true;
    },

    sharedBy(): string | null {
      if (this.data.shared_by == '[unknown]') {
        return this.t('memories', 'Shared');
      } else if (this.data.shared_by) {
        return this.data.shared_by;
      }
      return null;
    },

    /** Get current photo rating */
    currentRating(): number {
      const exif = this.data.imageInfo?.exif || this.data.exif;
      return utils.getRatingFromExif(exif);
    },

    /** Get current photo embedded tags */
    currentTags(): string[][] {
      const exif = this.data.imageInfo?.exif || this.data.exif;
      return utils.getTagsFromExif(exif);
    },

    /** Whether to show the RatingTags component */
    showRatingTags(): boolean {
      return this.config.metadata_in_gallery && (this.currentRating > 0 || this.currentTags.length > 0);
    },

    /** Whether to show the interactive rating overlay */
    showInteractiveRating(): boolean {
      return this.config.enable_exif_photo_rating_in_gallery && (!!this.data.exif || !!this.data.imageInfo?.exif);
    },

    /** Whether to show the metadata overlay container */
    showMetadataOverlay(): boolean {
      return this.showRatingTags || this.showInteractiveRating;
    },
  },

  methods: {
    /** Get url of the photo */
    url() {
      let base: 256 | 512 = 256;

      // Check if displayed size is larger than the image
      if (this.data.dispH! > base * 0.9 && this.data.dispW! > base * 0.9) {
        // Get a bigger image
        // 1. No trickery here, just get one size bigger. This is to
        //    ensure that the images can be cached even after reflow.
        // 2. Nextcloud only allows 4**x sized images, so technically
        //    this ends up being equivalent to 1024x1024.
        base = 512;
      }

      return utils.getPreviewUrl({
        photo: this.data,
        msize: base,
      });
    },

    /** Set src with overlay face rect */
    async addFaceRect() {
      if (!this.data.facerect || this.faceSrc) return;

      const img = this.refs.ximg?.$el;
      if (!img) return;

      // This is a hack to check if img is actually loaded.
      //   XImg loads an empty image, which may sometimes show up here
      //   If the size is less than 5px it is probably this dummy image
      //   Either way, the user cannot see anything if the image is this small
      //   so there's no point in trying to draw the face rect
      if (!img || img.naturalWidth < 5) return;

      const canvas = document.createElement('canvas');
      const context = canvas.getContext('2d');
      if (!context) return; // failed to create canvas

      canvas.width = img.naturalWidth;
      canvas.height = img.naturalHeight;
      context.drawImage(img, 0, 0);
      context.strokeStyle = '#00ff00';
      context.lineWidth = 2;
      context.strokeRect(
        this.data.facerect.x * img.naturalWidth,
        this.data.facerect.y * img.naturalHeight,
        this.data.facerect.w * img.naturalWidth,
        this.data.facerect.h * img.naturalHeight,
      );

      canvas.toBlob(
        (blob) => {
          if (!blob) return;
          this.faceSrc = URL.createObjectURL(blob);
        },
        'image/jpeg',
        0.95,
      );
    },

    /** Post load tasks */
    load() {
      this.addFaceRect();
    },

    /** Error in loading image */
    error(e: Error) {
      this.data.flag |= this.c.FLAG_LOAD_FAIL;
    },

    contextmenu(e: Event) {
      e.preventDefault();
      e.stopPropagation();
    },

    /** Start preview video */
    playVideo() {
      if (this.data.flag & this.c.FLAG_SELECTED) return;
      this.liveState.waiting = true;

      // Quickly moving over the icon causes unnecessary
      // transcoding requests which are expensive
      utils.setRenewingTimeout(
        this.liveState,
        'playTimer',
        async () => {
          const video = this.refs.video;
          if (!video || this.data.flag & this.c.FLAG_SELECTED) return;

          try {
            this.liveState.requested = true;
            video.currentTime = 0;
            video.loop = true;
            await video.play();
          } catch (e) {
            // ignore, pause was probably called too soon
          } finally {
            this.liveState.waiting = false;
          }
        },
        this.liveState.requested ? 0 : 300, // delay only the first play
      );
    },

    /** Stop preview video */
    stopVideo() {
      this.refs.video?.pause();
      window.clearTimeout(this.liveState.playTimer);
      this.liveState.playTimer = 0;
      this.liveState.waiting = false;
    },

    /** Start/stop preview video for touchscreens */
    touchVideo() {
      if (this.liveState.playing) this.stopVideo();
      else this.playVideo();
    },

    /** Update photo rating */
    async updateRating(rating: number) {
      const exif = this.data.imageInfo?.exif || this.data.exif;
      if (!exif) return;
      
      try {
        const fileid = this.data.fileid;
        const currentRating = exif.Rating || 0;
        const newRating = rating === currentRating ? undefined : rating;
        
        // Optimistically update the UI
        if (newRating === undefined) {
          delete exif.Rating;
        } else {
          exif.Rating = newRating;
        }
        
        // Update the server
        await axios.patch(API.IMAGE_SETEXIF(fileid), { 
          raw: { Rating: newRating }
        });
        
        // Emit file updated event
        utils.bus.emit('files:file:updated', { fileid });
        
      } catch (e) {
        console.error('Failed to update rating for', this.data.fileid, e);
        if (e.response?.data?.message) {
          showError(e.response.data.message);
        } else {
          showError(this.t('memories', 'Failed to update rating'));
        }
        
        // Revert the optimistic update on error
        this.$forceUpdate();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
  & {
    padding: 2px;
    --icon-dist: 8px;

    transition:
      background-color 0.15s ease,
      opacity 0.2s ease-in,
      transform 0.2s ease-in;
  }

  @media (max-width: 768px) {
    padding: 1px;
    --icon-dist: 4px;
  }

  &.leaving {
    transform: scale(0.9);
    opacity: 0;
  }

  &.selected {
    background-color: var(--color-primary-select-light);
    background-clip: content-box;
  }
}

// Distance of icon from border
$icon-half-size: 6px;
$icon-size: $icon-half-size * 2;

// Selection icon
// Not the same as any other flag because it does not
// translate when selected, and changes color
.select {
  position: absolute;
  top: calc(var(--icon-dist) + 2px);
  right: calc(var(--icon-dist) + 2px);
  z-index: 100;
  border-radius: 50%;
  display: none;
  opacity: 0.7;

  @mixin visible {
    display: flex;
    opacity: 1;
  }

  @media (hover: hover) and (pointer: fine) {
    .p-outer:hover > & {
      @include visible;
    }
  }

  & {
    filter: invert(1) brightness(100);
  }

  .p-outer.selected > & {
    @include visible;
    filter: invert(0);
    background-color: white;
    color: var(--color-primary);
  }

  .check-circle-icon {
    cursor: pointer;

    // Extremely ugly way to fill up the space
    // If this isn't done, bg has a border
    :deep path {
      transform: scale(1.2) translate(-2px, -2px);
    }
  }
}

// Flags to show on timeline
.flag {
  position: absolute;
  z-index: 100;
  pointer-events: none;
  transition: transform 0.15s ease;
  color: white;
  display: flex;

  &.top-right {
    top: var(--icon-dist);
    right: var(--icon-dist);
    .p-outer.selected > & {
      transform: translate(-$icon-size, $icon-size);
    }
  }

  &.bottom-left {
    bottom: var(--icon-dist);
    left: var(--icon-dist);
    .p-outer.selected > & {
      transform: translate($icon-size, -$icon-size);
    }
  }

  &.bottom-right {
    bottom: var(--icon-dist);
    right: var(--icon-dist);
    .p-outer.selected > & {
      transform: translate(-$icon-size, -$icon-size);
    }
  }

  > .shared-by {
    font-size: 0.75em;
    line-height: 0.75em;
    font-weight: 400;
    margin: 2px;
  }

  > .video {
    display: flex;
    line-height: 22px; // force text height to match

    > .time {
      font-size: 0.75em;
      font-weight: bold;
      margin-right: 3px;
    }
  }

  > .livephoto {
    pointer-events: auto; // hover to play
  }
  > .raw {
    height: 22px; // force height to match
  }
}

// Actual image
div.img-outer {
  position: relative;
  box-sizing: border-box;
  padding: 0;
  cursor: pointer;

  transition: padding 0.15s ease;
  .p-outer.selected > & {
    padding: calc(var(--icon-dist) + $icon-half-size);
  }

  .p-outer.placeholder > & {
    background-color: var(--color-background-dark);
    background-clip: content-box, padding-box;
  }

  > .ximg {
    background-clip: content-box;
    object-fit: cover;
    z-index: 1;
    background-color: var(--color-background-dark);

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    pointer-events: none;
    transition:
      border-radius 0.1s ease-in,
      transform 0.3s ease-in-out;

    .p-outer.placeholder > & {
      display: none;
    }
    .p-outer.error & {
      object-fit: contain;
    }
  }

  > video {
    pointer-events: none;
    object-fit: cover;
    z-index: 2;
  }

  > .overlay {
    pointer-events: none;
    z-index: 3;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.2) 0%, transparent 30%);

    display: none;
    transition: border-radius 0.1s ease-in;
    @media (hover: hover) and (pointer: fine) {
      .p-outer:not(.selected):hover > & {
        display: block;
      }
    }
  }

  > * {
    @media (max-width: 768px) {
      .selected > & {
        border-radius: $icon-size;
        border-top-left-radius: 0;
      }
    }
  }
}

// Metadata overlay
.metadata-overlay {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 50;
  pointer-events: none;
  background: linear-gradient(180deg, transparent 0%, rgba(0, 0, 0, 0.7) 100%);
  
  // Hide on hover to prevent interference with selection
  @media (hover: hover) and (pointer: fine) {
    .p-outer:hover > .img-outer > & {
      opacity: 0.3;
      transition: opacity 0.2s ease;
    }
  }
  
  // Hide when selected
  .p-outer.selected > .img-outer > & {
    opacity: 0;
  }

  // Compact styling for gallery
  :deep .rating-tags {
    font-size: 0.8em;
    gap: 6px;
    
    .rating-section {
      :deep .rating-stars {
        gap: 0;
        
        .button-vue {
          padding: 2px;
          min-height: unset;
          min-width: unset;
        }
      }
    }
    
    .tags-container {
      gap: 2px;
      
      .chip {
        font-size: 0.75em;
        padding: 2px 6px;
        max-width: 80px;
        
        :deep .chip__content {
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    }
    
    .more-tags {
      font-size: 0.7em;
    }
  }

  @media (max-width: 768px) {
    bottom: 2px;
    left: 2px;
    right: 2px;
    padding: 6px 4px 2px;
    
    :deep .rating-tags {
      font-size: 0.75em;
      gap: 4px;
    }
  }
}

// Interactive rating overlay within metadata
.interactive-rating {
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  justify-content: flex-start;
  align-items: flex-end;
  padding: 8px 6px 4px;
  opacity: 0.7;
  transition: opacity 0.2s ease;
  pointer-events: auto;
  z-index: 10;
  
  // Show on hover or when photo has rating
  @media (hover: hover) and (pointer: fine) {
    .p-outer:hover & {
      opacity: 1;
    }
  }
  
  // Always show if there's a rating
  &:has(.rating-stars .button-vue.filled) {
    opacity: 1;
  }
  
  // Show on touch devices
  @media (hover: none) {
    .p-outer:active &,
    .p-outer.touched & {
      opacity: 1;
    }
  }
  
  // Hide when photo is selected
  .p-outer.selected & {
    opacity: 0;
    pointer-events: none;
  }
  
  // Compact rating stars styling for gallery
  :deep .rating-stars {
    gap: 1px !important;
    
    .button-vue {
      padding: 2px;
      min-height: 18px;
      min-width: 18px;
    }
  }

  @media (max-width: 768px) {
    padding: 6px 4px 2px;
    
    :deep .rating-stars .button-vue {
      min-height: 16px;
      min-width: 16px;
    }
  }
}
</style>
