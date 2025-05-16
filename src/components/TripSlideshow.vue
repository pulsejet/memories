<template>
  <div class="trip-slideshow" @keydown="handleKeyDown">
    <div v-if="loading" class="loading-screen">
      <span class="icon icon-loading" />
      <span>Loading slideshow...</span>
    </div>

    <div v-else-if="!currentMedia" class="empty-state">
      <span>No media available for this trip</span>
    </div>

    <div v-else class="slideshow-container">
      <!-- Image slide -->
      <div v-if="currentMedia.type === 'image'" class="slide image-slide" :class="{ 'fade-in': transitioning }">
        <div v-if="!imageError" class="ken-burns-container" :style="currentKenBurnsStyle">
          <img
            :src="currentMedia.previewUrl + '?x=2048&y=2048'"
            :alt="`Image from ${formatDate(currentMedia.datetaken)}`"
            @load="imageLoaded"
            @error="handleMissingMedia"
          />
        </div>

        <!-- Fallback for when image preview fails (likely motion photos) -->
        <div v-else class="fallback-image">
          <span class="icon icon-image"></span>
          <p>Unable to display preview for this media type</p>
          <p class="filename">{{ currentMedia.path ? currentMedia.path.split('/').pop() : '' }}</p>
          <button class="primary-action" @click="downloadCurrentMedia">Download</button>
        </div>

        <!-- Display image counter for better user feedback -->
        <div class="image-counter">{{ currentIndex + 1 }} / {{ mediaItems.length }}</div>
      </div>

      <!-- Video slide -->
      <div v-else-if="currentMedia.type === 'video'" class="slide video-slide" :class="{ 'fade-in': transitioning }">
        <video
          ref="videoPlayer"
          controls
          autoplay
          @ended="handleVideoEnded"
          @error="handleVideoError"
          @loadeddata="videoLoaded"
        >
          <source :src="currentMedia.downloadUrl" :type="currentMedia.mime" />
          Your browser does not support the video tag.
        </video>
      </div>

      <!-- Close button -->
      <button class="close-button" @click="$emit('close')">
        <span class="icon icon-close"></span>
      </button>

      <!-- Slideshow controls -->
      <div class="slideshow-controls">
        <button class="control-button" @click="prevSlide">
          <span class="icon icon-previous"></span>
        </button>

        <button class="control-button" @click="togglePlayPause">
          <span v-if="isPlaying" class="icon icon-pause"></span>
          <span v-else class="icon icon-play"></span>
        </button>

        <button class="control-button" @click="nextSlide">
          <span class="icon icon-next"></span>
        </button>
      </div>

      <!-- Progress indicator -->
      <div class="progress-bar">
        <div class="progress-indicator" :style="{ width: `${progressPercentage}%` }" />
      </div>

      <!-- Date overlay -->
      <div class="date-overlay" v-if="currentMedia">
        {{ formatDate(currentMedia.datetaken) }}
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, ref, onMounted, onBeforeUnmount, watch, computed } from 'vue';
import * as utils from '../services/utils';
import * as dav from '../services/dav/trips';

export default defineComponent({
  name: 'TripSlideshow',

  props: {
    tripId: {
      type: Number,
      required: true,
    },
    maxVideoDuration: {
      type: Number,
      default: 10,
    },
  },

  setup(props, { emit }) {
    const loading = ref(true);
    const error = ref(null);
    const imageError = ref(false);
    const currentIndex = ref(0);
    const mediaItems = ref<dav.ITripMedia[]>([]);
    const currentMedia = ref<dav.ITripMedia | null>(null);
    const videoPlayer = ref<HTMLVideoElement | null>(null);
    const isPlaying = ref(true);
    const transitioning = ref(false);
    const slideshowInterval = ref<number | undefined>(undefined);
    const currentKenBurnsStyle = ref({});
    const videoTimeoutId = ref<number | undefined>(undefined);

    // Format date helper function
    const formatDate = (dateString: string) => {
      const date = new Date(dateString);
      return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      });
    };

    // Load slideshow media
    const loadMedia = async () => {
      loading.value = true;
      error.value = null;
      imageError.value = false;
      try {
        const items = await dav.getTripSlideshowMedia(props.tripId, props.maxVideoDuration);
        mediaItems.value = items;
        if (items.length > 0) {
          currentIndex.value = 0;
          currentMedia.value = items[0];
          isPlaying.value = true; // Start playing automatically
        } else {
          currentMedia.value = null;
        }
      } catch (err) {
        console.error('Error loading slideshow media:', err);
        error.value = err;
        currentMedia.value = null;
      } finally {
        loading.value = false;
      }
    };

    // Play controls
    const play = () => {
      isPlaying.value = true;

      // Force immediate transition if we've been paused for a while
      const forcePlayNow = () => {
        if (currentMedia.value?.type === 'image') {
          scheduleNextSlide();
        } else if (currentMedia.value?.type === 'video' && videoPlayer.value) {
          videoPlayer.value.play().catch((err) => console.error('Error playing video:', err));
        }
      };

      // Short delay to allow any UI updates to complete
      setTimeout(forcePlayNow, 100);
    };

    const pause = () => {
      isPlaying.value = false;
      if (slideshowInterval.value !== undefined) {
        clearTimeout(slideshowInterval.value);
        slideshowInterval.value = undefined;
      }

      // Pause video if current media is a video
      if (currentMedia.value?.type === 'video' && videoPlayer.value) {
        videoPlayer.value.pause();
      }
    };

    const togglePlayPause = () => {
      if (isPlaying.value) {
        pause();
      } else {
        // Resume video playback if current media is a video
        if (currentMedia.value?.type === 'video' && videoPlayer.value) {
          videoPlayer.value.play();
        }
        play();
      }
    };

    // Navigation
    const nextSlide = () => {
      // Clear any pending timers
      if (slideshowInterval.value !== undefined) {
        clearTimeout(slideshowInterval.value);
        slideshowInterval.value = undefined;
      }

      if (videoTimeoutId.value !== undefined) {
        clearTimeout(videoTimeoutId.value);
        videoTimeoutId.value = undefined;
      }

      transitioning.value = true;

      // Reset image error state
      imageError.value = false;

      // Move to the next slide or loop back to the beginning
      if (currentIndex.value < mediaItems.value.length - 1) {
        currentIndex.value++;
      } else {
        currentIndex.value = 0;
      }

      // Update current media
      currentMedia.value = mediaItems.value[currentIndex.value] || null;
    };

    const prevSlide = () => {
      if (slideshowInterval.value !== undefined) {
        clearTimeout(slideshowInterval.value);
        slideshowInterval.value = undefined;
      }

      if (videoTimeoutId.value !== undefined) {
        clearTimeout(videoTimeoutId.value);
        videoTimeoutId.value = undefined;
      }

      transitioning.value = true;

      // Reset image error state
      imageError.value = false;

      // Move to the previous slide or loop back to the end
      if (currentIndex.value > 0) {
        currentIndex.value--;
      } else {
        currentIndex.value = mediaItems.value.length - 1;
      }

      // Update current media
      currentMedia.value = mediaItems.value[currentIndex.value] || null;
    };

    // Schedule transitions
    const scheduleNextSlide = () => {
      // Always clear any existing timeout first to prevent duplicate transitions
      if (slideshowInterval.value !== undefined) {
        clearTimeout(slideshowInterval.value);
        slideshowInterval.value = undefined;
      }

      // If current item is an image, auto-advance after imageDuration
      if (currentMedia.value?.type === 'image') {
        // Use a longer display duration for better viewing experience
        const duration = currentMedia.value.displayDuration || 5; // default to 5 seconds
        console.log(`Scheduling next slide in ${duration} seconds`);
        slideshowInterval.value = window.setTimeout(() => {
          console.log('Auto-advancing to next slide');
          nextSlide();
        }, duration * 1000);
      } else if (currentMedia.value?.type === 'video') {
        // Also set a maximum duration for videos to ensure we don't get stuck
        const maxVideoDuration = props.maxVideoDuration || 30; // default to 30 seconds max
        console.log(`Setting video timeout fallback for ${maxVideoDuration} seconds`);
        // Clear any existing video timeout
        if (videoTimeoutId.value !== undefined) {
          clearTimeout(videoTimeoutId.value);
        }
        // Set a timeout to force progression if video doesn't trigger ended event
        videoTimeoutId.value = window.setTimeout(() => {
          console.log('Video timeout reached, forcing next slide');
          nextSlide();
        }, maxVideoDuration * 1000);
      }
    };

    // Media loaded handlers
    const imageLoaded = () => {
      transitioning.value = false;

      // Generate random Ken Burns effect
      applyKenBurnsEffect();

      // Schedule next slide if in auto-play mode
      if (isPlaying.value) {
        scheduleNextSlide();
      }
    };

    const videoLoaded = () => {
      transitioning.value = false;

      if (!videoPlayer.value) return;

      // Set muted state based on user preference
      videoPlayer.value.muted = false;

      // Set playback rate to 1 (normal speed)
      videoPlayer.value.playbackRate = 1.0;

      // Add timeupdate listener to update progress
      const checkTimeUpdate = () => {
        if (!videoPlayer.value || !currentMedia.value) return;

        // Check if we're near the end of the video to handle cases where ended event might not fire
        if (
          videoPlayer.value.duration > 0 &&
          videoPlayer.value.currentTime > 0 &&
          videoPlayer.value.currentTime >= videoPlayer.value.duration - 0.5
        ) {
          console.log('Video near end detected, preparing to advance');
          // Only trigger if we haven't already scheduled the next slide
          if (slideshowInterval.value === undefined) {
            handleVideoEnded();
          }
        }
      };

      videoPlayer.value.addEventListener('timeupdate', checkTimeUpdate);

      // Make sure video fills available space while maintaining aspect ratio
      videoPlayer.value.style.maxWidth = '90%';
      videoPlayer.value.style.maxHeight = '90%';

      // Ensure we move to next slide when video ends
      videoPlayer.value.onended = () => {
        console.log('Video onended event fired');
        handleVideoEnded();
      };

      // Schedule a fallback timeout for videos
      if (isPlaying.value) {
        scheduleNextSlide();
      }
    };

    // Handle video ended event
    const handleVideoEnded = () => {
      console.log('Video ended, moving to next slide');

      // Clear any video timeout that might be pending
      if (videoTimeoutId.value !== undefined) {
        clearTimeout(videoTimeoutId.value);
        videoTimeoutId.value = undefined;
      }

      // Ensure we always move to the next slide when video ends
      nextSlide();
    };

    // Handle video playback errors
    const handleVideoError = () => {
      console.error('Video playback error, advancing to next slide');
      imageError.value = true; // Use the same error state for simplicity

      // Move to next slide after a brief delay
      setTimeout(() => {
        nextSlide();
      }, 2000);
    };

    // Apply a random Ken Burns effect (zoom and pan)
    const applyKenBurnsEffect = () => {
      // Reset any previous transition
      transitioning.value = false;

      // Generate random values for the Ken Burns effect
      const startScale = 1.0;
      const endScale = 1.0 + Math.random() * 0.3; // Scale between 1.0 and 1.3

      // Random starting positions (as percentages)
      const startX = Math.random() * 10; // 0% to 10%
      const startY = Math.random() * 10; // 0% to 10%

      // Random ending positions that create motion
      const endX = startX + (Math.random() * 10 - 5); // Move -5% to +5% from start
      const endY = startY + (Math.random() * 10 - 5); // Move -5% to +5% from start

      // Apply styles with a CSS transition that will run for the duration of the slide
      currentKenBurnsStyle.value = {
        animation: 'none', // Reset any previous animation
        transform: `scale(${startScale}) translate(${-startX}%, ${-startY}%)`,
        transition: 'none',
      };

      // Force a reflow before starting the animation
      setTimeout(() => {
        currentKenBurnsStyle.value = {
          transform: `scale(${endScale}) translate(${-endX}%, ${-endY}%)`,
          transition: 'transform 8s ease-in-out',
        };
      }, 50);
    };

    // Format date for display
    const progressPercentage = computed(() => {
      if (mediaItems.value.length <= 1) return 100;
      return (currentIndex.value / (mediaItems.value.length - 1)) * 100;
    });

    // Keyboard navigation
    const handleKeyDown = (e: KeyboardEvent) => {
      switch (e.key) {
        case 'ArrowRight': // Right arrow key
          nextSlide();
          break;
        case 'ArrowLeft': // Left arrow key
          prevSlide();
          break;
        case ' ': // Space key
          togglePlayPause();
          break;
        case 'Escape': // ESC key
          pause();
          emit('close');
          break;
      }
    };

    // Watch for tripId changes
    watch(
      () => props.tripId,
      () => {
        loadMedia();
      },
    );

    // Lifecycle hooks
    onMounted(() => {
      loadMedia().then(() => {
        // Start the slideshow automatically after media is loaded
        if (mediaItems.value.length > 0 && isPlaying.value) {
          // Small delay to ensure rendering is complete
          setTimeout(() => {
            // If image is already loaded, schedule next transition
            if (currentMedia.value?.type === 'image') {
              scheduleNextSlide();
            }
          }, 500);
        }
      });
      document.addEventListener('keydown', handleKeyDown);
    });

    onBeforeUnmount(() => {
      if (slideshowInterval.value !== undefined) {
        clearTimeout(slideshowInterval.value);
        slideshowInterval.value = undefined;
      }

      if (videoTimeoutId.value !== undefined) {
        clearTimeout(videoTimeoutId.value);
        videoTimeoutId.value = undefined;
      }

      document.removeEventListener('keydown', handleKeyDown);

      // Cleanup video event listeners
      if (videoPlayer.value) {
        videoPlayer.value.pause();
      }
    });

    // Handle missing media files
    const handleMissingMedia = () => {
      console.warn(`Media file not found: ${currentMedia.value?.id}`);

      // Continue to next slide automatically if playing
      nextSlide();

      // Schedule next slide if we're in playing mode
      if (isPlaying.value) {
        scheduleNextSlide();
      }
    };

    // Download the current media (useful for fallback option)
    const downloadCurrentMedia = () => {
      if (currentMedia.value) {
        window.open(currentMedia.value.downloadUrl, '_blank');
      }
    };

    return {
      loading,
      error,
      imageError,
      currentIndex,
      mediaItems,
      currentMedia,
      isPlaying,
      transitioning,
      videoPlayer,
      progressPercentage,
      currentKenBurnsStyle,
      nextSlide,
      prevSlide,
      togglePlayPause,
      formatDate,
      imageLoaded,
      videoLoaded,
      handleVideoEnded,
      handleVideoError,
      handleKeyDown,
      handleMissingMedia,
      downloadCurrentMedia,
    };
  },
});
</script>

<style scoped>
.trip-slideshow {
  position: fixed;
  top: 0;
  left: var(--app-navigation-width, 300px);
  width: calc(100% - var(--app-navigation-width, 300px));
  height: 100%;
  background-color: rgba(0, 0, 0, 0.9);
  z-index: 1000;
  display: flex;
  justify-content: center;
  align-items: center;
}

.loading-screen,
.empty-state {
  color: #fff;
  font-size: 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.slideshow-container {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.slide {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  justify-content: center;
  align-items: center;
  transition: opacity 0.5s ease;
}

.slide.fade-in {
  opacity: 0;
}

.image-slide {
  width: 100%;
  height: 100%;
  display: flex;
  justify-content: center;
  align-items: center;
  overflow: hidden;
}

.ken-burns-container {
  max-width: 90%;
  max-height: 90%;
  width: auto;
  height: auto;
  overflow: hidden;
  display: flex;
  justify-content: center;
  align-items: center;
  box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
}

.ken-burns-container img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.image-slide img,
.video-slide video {
  max-width: 100%;
  max-height: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
}

.close-button {
  position: absolute;
  top: 1rem;
  right: 1rem;
  background-color: rgba(0, 0, 0, 0.5);
  border: none;
  border-radius: 50%;
  width: 2.5rem;
  height: 2.5rem;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  z-index: 9200;
  transition: background-color 0.2s;
}

.close-button:hover {
  background-color: rgba(255, 255, 255, 0.2);
}

.close-button .icon {
  color: #fff;
  font-size: 1.2rem;
}

.slideshow-controls {
  position: absolute;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 1rem;
  z-index: 9100;
}

.control-button {
  background-color: rgba(0, 0, 0, 0.5);
  border: 2px solid #fff;
  border-radius: 50%;
  width: 3rem;
  height: 3rem;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  transition: background-color 0.2s;
}

.control-button:hover {
  background-color: rgba(255, 255, 255, 0.2);
}

.control-button .icon {
  color: #fff;
  font-size: 1.5rem;
}

.progress-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 5px;
  background-color: rgba(255, 255, 255, 0.2);
  z-index: 9100;
}

.progress-indicator {
  height: 100%;
  background-color: var(--color-primary, #0082c9);
  transition: width 0.3s;
}

.date-overlay {
  position: absolute;
  top: 1rem;
  left: 1rem;
  background-color: rgba(0, 0, 0, 0.5);
  color: #fff;
  padding: 0.5rem 1rem;
  border-radius: 4px;
  font-size: 0.9rem;
  z-index: 9100;
}

.image-counter {
  position: absolute;
  top: 1rem;
  right: 1rem;
  background-color: rgba(0, 0, 0, 0.5);
  color: #fff;
  padding: 0.5rem 1rem;
  border-radius: 4px;
  font-size: 0.9rem;
  z-index: 9100;
}

.fallback-image {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.6);
  padding: 2rem;
  border-radius: 8px;
  color: white;
  text-align: center;
}

.fallback-image .icon {
  font-size: 4rem;
  margin-bottom: 1rem;
}

.fallback-image .filename {
  font-style: italic;
  opacity: 0.8;
  margin-bottom: 1rem;
}

.primary-action {
  background-color: var(--color-primary, #0082c9);
  color: white;
  border: none;
  border-radius: 4px;
  padding: 0.5rem 1rem;
  font-weight: bold;
  cursor: pointer;
  margin-top: 1rem;
}

.primary-action:hover {
  opacity: 0.9;
}
</style>
