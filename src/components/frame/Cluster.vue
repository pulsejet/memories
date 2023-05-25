<template>
  <router-link draggable="false" class="cluster fill-block" :class="{ error }" :to="target" @click.native="click">
    <div class="count-bubble" v-if="data.count">
      <NcCounterBubble> {{ data.count }} </NcCounterBubble>
    </div>
    <div class="name">
      {{ title }}
      <span class="subtitle" v-if="subtitle"> {{ subtitle }} </span>
    </div>

    <div class="previews fill-block" ref="previews">
      <div class="img-outer" :class="{ plus }">
        <XImg
          draggable="false"
          class="fill-block"
          :class="{ error }"
          :key="data.cluster_id"
          :src="previewUrl"
          @error="failed"
        />
        <div v-if="title || subtitle" class="overlay fill-block" />
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';

import { getCurrentUser } from '@nextcloud/auth';
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble';

import type { IAlbum, ICluster, IFace, IPhoto } from '../../types';
import { getPreviewUrl } from '../../services/utils/helpers';
import errorsvg from '../../assets/error.svg';
import plussvg from '../../assets/plus.svg';

import { API } from '../../services/API';

import Vue from 'vue';

export default defineComponent({
  name: 'Cluster',
  components: {
    NcCounterBubble,
  },

  props: {
    data: {
      type: Object as PropType<ICluster>,
      required: true,
    },
    link: {
      type: Boolean,
      default: true,
    },
  },

  computed: {
    previewUrl() {
      if (this.error) return errorsvg;
      if (this.plus) return plussvg;

      if (this.album) {
        const mock = {
          fileid: this.album.last_added_photo,
          etag: this.album.album_id,
          flag: 0,
        } as unknown as IPhoto;
        return getPreviewUrl(mock, true, 512);
      }

      return API.CLUSTER_PREVIEW(this.data.cluster_type, this.data.cluster_id);
    },

    title() {
      if (this.tag) {
        return this.t('recognize', this.tag.name);
      }

      return this.data.name;
    },

    subtitle() {
      if (this.album && this.album.user !== getCurrentUser()?.uid) {
        return `(${this.album.user})`;
      }

      return '';
    },

    plus() {
      return this.data.cluster_type === 'plus';
    },

    tag() {
      return this.data.cluster_type === 'tags' && this.data;
    },

    face() {
      return (
        (this.data.cluster_type === 'recognize' || this.data.cluster_type === 'facerecognition') && (this.data as IFace)
      );
    },

    place() {
      return this.data.cluster_type === 'places' && this.data;
    },

    album() {
      return this.data.cluster_type === 'albums' && (this.data as IAlbum);
    },

    /** Target URL to navigate to */
    target() {
      if (!this.link || this.plus) return {};

      if (this.album) {
        const user = this.album.user;
        const name = this.album.name;
        return { name: 'albums', params: { user, name } };
      }

      if (this.face) {
        const name = String(this.face.name || this.face.cluster_id);
        const user = this.face.user_id;
        return { name: this.data.cluster_type, params: { name, user } };
      }

      if (this.place) {
        const id = this.place.cluster_id;
        const placeName = this.place.name || id;
        const name = `${id}-${placeName}`;
        return { name: 'places', params: { name } };
      }

      return { name: 'tags', params: { name: this.data.name } };
    },

    error() {
      return Boolean(this.data.previewError) || Boolean(this.album && this.album.last_added_photo <= 0);
    },
  },
  methods: {
    failed() {
      Vue.set(this.data, 'previewError', true);
    },
    click() {
      this.$emit('click', this.data);
    },
  },
});
</script>

<style lang="scss" scoped>
.cluster,
.name,
img {
  cursor: pointer;
}

// Get rid of color of the bubble
.cluster .count-bubble :deep .counter-bubble__counter {
  color: unset !important;
}

.name {
  position: absolute;
  bottom: 2%;
  z-index: 100;
  width: 100%;
  max-height: 75%;
  padding: 5%;

  color: white;
  word-wrap: break-word;
  white-space: normal;
  text-overflow: ellipsis;
  text-align: center;
  font-size: 1em;
  line-height: 1.1em;

  > .subtitle {
    font-size: 0.7em;
    margin-top: 2px;
    display: block;
  }

  .cluster.error > & {
    color: unset;
  }

  @media (max-width: 768px) {
    font-size: 0.9em;
  }
}

.count-bubble {
  z-index: 100;
  position: absolute;
  top: 6px;
  right: 6px;
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;

  > .img-outer {
    position: relative;
    background-color: var(--color-background-dark);
    border-radius: 10px;
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    display: inline-block;
    cursor: pointer;

    &.plus {
      background-color: var(--color-primary-element-light);
      color: var(--color-primary);
    }

    > img {
      object-fit: cover;
      padding: 0;
      cursor: pointer;
    }

    > .overlay {
      pointer-events: none;
      position: absolute;
      top: 0;
      left: 0;
      background: linear-gradient(0deg, rgba(0, 0, 0, 0.7) 10%, transparent 35%);

      .cluster.error & {
        display: none;
      }
    }
  }
}
</style>
