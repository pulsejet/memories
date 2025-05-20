<template>
  <div class="trip-videos-container">
    <div class="trip-videos-header">
      <h2>{{ t('memories', 'Trip Videos') }}</h2>
      <p v-if="videos.length === 0 && !loading">
        {{ t('memories', 'No trip videos available. Generate videos using the command:') }}
        <code>occ memories:trips:generate-video &lt;user-id&gt; --all</code>
      </p>
    </div>

    <XLoadingIcon v-if="loading" />

    <div v-else class="trip-videos-grid">
      <div v-for="video in videos" :key="video.id" class="trip-video-card" @click="openVideo(video)">
        <div class="trip-video-info">
          <div class="trip-video-title">{{ video.name }}</div>
          <div class="trip-video-details">
            <div v-if="video.location" class="trip-video-location">
              <NcIconLocation :size="16" />
              <span>{{ video.location }}</span>
            </div>
            <div class="trip-video-timeframe">
              <NcIconCalendar :size="16" />
              <span>{{ video.timeframe }}</span>
            </div>
          </div>
        </div>
        <div class="trip-video-play">
          <NcIconPlay :size="36" />
        </div>
      </div>
    </div>

    <div v-if="selectedVideo" class="trip-video-viewer">
      <div class="trip-video-viewer-header">
        <h3>{{ selectedVideo.name }}</h3>
        <button class="icon-close" @click="closeVideo"></button>
      </div>
      <video controls autoplay :src="selectedVideo.url" @error="handleVideoError"></video>
    </div>

    <div v-if="error" class="notification error">{{ error }}</div>
  </div>
</template>

<script>
import { translate as t } from '@services/l10n';
import TripVideosService from '@services/TripVideosService';

import XLoadingIcon from '@components/XLoadingIcon.vue';
import NcIconLocation from 'vue-material-design-icons/MapMarker.vue';
import NcIconCalendar from 'vue-material-design-icons/Calendar.vue';
import NcIconPlay from 'vue-material-design-icons/Play.vue';

export default {
  name: 'TripVideosList',

  components: {
    XLoadingIcon,
    NcIconLocation,
    NcIconCalendar,
    NcIconPlay,
  },

  data() {
    return {
      videos: [],
      loading: true,
      error: null,
      selectedVideo: null,
    };
  },

  mounted() {
    this.fetchVideos();
  },

  methods: {
    t,

    async fetchVideos() {
      this.loading = true;
      this.error = null;

      try {
        this.videos = await TripVideosService.list();
      } catch (error) {
        console.error('Failed to fetch trip videos', error);
        this.error = t('memories', 'Failed to load trip videos. {message}', {
          message: error.response?.data?.message || error.message,
        });
      } finally {
        this.loading = false;
      }
    },

    openVideo(video) {
      this.selectedVideo = video;
    },

    closeVideo() {
      this.selectedVideo = null;
    },

    handleVideoError(event) {
      console.error('Video playback error', event);
      this.error = t('memories', 'Failed to play video');
    },
  },
};
</script>

<style scoped>
.trip-videos-container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
}

.trip-videos-header {
  margin-bottom: 20px;
}

.trip-videos-header h2 {
  font-size: 24px;
  margin-bottom: 10px;
}

.trip-videos-header code {
  background-color: var(--color-background-dark);
  padding: 5px 8px;
  border-radius: 3px;
  display: block;
  margin-top: 5px;
  font-family: monospace;
}

.trip-videos-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 25px;
}

.trip-video-card {
  background-color: var(--color-background-hover);
  border-radius: 8px;
  padding: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  transition:
    transform 0.2s,
    box-shadow 0.2s;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.trip-video-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
}

.trip-video-info {
  flex: 1;
}

.trip-video-title {
  font-weight: bold;
  font-size: 16px;
  margin-bottom: 8px;
}

.trip-video-details {
  font-size: 14px;
  color: var(--color-text-maxcontrast);
}

.trip-video-location,
.trip-video-timeframe {
  display: flex;
  align-items: center;
  margin-bottom: 4px;
}

.trip-video-location span,
.trip-video-timeframe span {
  margin-left: 4px;
}

.trip-video-play {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background-color: var(--color-primary);
  color: #fff;
  margin-left: 16px;
}

.trip-video-viewer {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.9);
  z-index: 10000;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.trip-video-viewer-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  max-width: 800px;
  padding: 10px;
  color: #fff;
}

.trip-video-viewer video {
  max-width: 90%;
  max-height: 70vh;
  box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
}

.notification.error {
  background-color: var(--color-error);
  color: var(--color-primary-text);
  padding: 10px 15px;
  border-radius: 5px;
  margin: 20px 0;
}
</style>
