<template>
  <router-link draggable="false" class="cluster fill-block" :class="{ error }" :to="target" @click.native="click">
    <div class="count-bubble" v-if="counters && data.count">
      <NcCounterBubble> {{ data.count }} </NcCounterBubble>
    </div>
    <div class="name">
      <div class="title">{{ title }}</div>
      <div class="subtitle" v-if="subtitle">{{ subtitle }}</div>
    </div>

    <div class="previews fill-block" ref="previews" @click="clickPreview">
      <div class="img-outer" :class="{ plus }">
        <XImg
          draggable="false"
          class="fill-block"
          :class="{ error }"
          :key="data.cluster_id"
          :src="previewUrl"
          :svg-tag="plus"
          @error="failed"
        />
        <div v-if="title || subtitle" class="overlay fill-block" />
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';

import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble';

import type { IAlbum, ICluster, IFace, IPhoto } from '../../types';
import { getPreviewUrl } from '../../services/utils/helpers';
import errorsvg from '../../assets/error.svg';
import plussvg from '../../assets/plus.svg';

import * as utils from '../../services/utils';
import * as nativex from '../../native';
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
    counters: {
      type: Boolean,
      default: true,
    },
  },

  emits: {
    click: (item: ICluster) => true,
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
        return getPreviewUrl({ photo: mock, sqsize: 512 });
      }

      return API.CLUSTER_PREVIEW(this.data.cluster_type, this.data.cluster_id);
    },

    title() {
      return this.data.display_name || this.data.name;
    },

    subtitle() {
      if (this.album) {
        let text: string;
        if (this.album.count === 0) {
          text = this.t('memories', 'No items');
        } else {
          text = this.n('memories', '{n} item', '{n} items', this.album.count, { n: this.album.count });
        }

        if (this.album.user !== utils.uid) {
          text +=
            ' / ' +
            this.t('memories', 'Shared by {user}', {
              user: this.album.user_display || this.album.user,
            });
        }

        return text;
      }

      return '';
    },

    type() {
      return this.data.cluster_type;
    },

    plus() {
      return this.type === 'plus';
    },

    tag() {
      return this.type === 'tags' && this.data;
    },

    face() {
      return (this.type === 'recognize' || this.type === 'facerecognition') && (this.data as IFace);
    },

    place() {
      return this.type === 'places' && this.data;
    },

    album() {
      return this.type === 'albums' && (this.data as IAlbum);
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

    clickPreview() {
      nativex.playTouchSound();
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

.cluster {
  // Get rid of color of the bubble
  .count-bubble :deep .counter-bubble__counter {
    color: unset !important;
  }
}

$namemargin: 10px;
.name {
  position: absolute;
  bottom: 0;
  z-index: 100;
  width: calc(100% - 2 * #{$namemargin});
  margin: $namemargin;
  pointer-events: none;

  color: white;
  word-wrap: break-word;
  white-space: normal;
  text-align: center;
  font-size: 1em;
  line-height: 1.1em;

  // multiline ellipsis
  > .title {
    display: -webkit-box;
    -webkit-line-clamp: 5;
    -webkit-box-orient: vertical;
    overflow: hidden;

    // 2px padding prevents the bottom of the text from being cut off
    padding-bottom: 2px;
  }

  // name is below the image
  .cluster--circle & {
    margin: 0 $namemargin;
    min-height: 26px; // alignment
    font-weight: 500;
  }

  .cluster--circle &,
  .cluster--album &,
  .cluster.error & {
    color: unset;

    > .title {
      -webkit-line-clamp: 2;
    }
  }

  .cluster--album & {
    text-align: start;
    margin: 0;
    padding: 0 12px;

    min-height: 50px; // align to top of space
    @media (max-width: 768px) {
      min-height: 54px; // mark#2147915
      padding: 0 6px;
    }

    > .title {
      font-weight: 500;
    }

    > .subtitle {
      color: var(--color-text-lighter);
    }
  }

  @media (max-width: 768px) {
    font-size: 0.9em;
  }

  > .subtitle {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.87em;
  }
}

.count-bubble {
  z-index: 100;
  position: absolute;
  top: 6px;
  right: 6px;
  pointer-events: none;
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;

  .cluster--album & {
    padding: 12px;

    @media (max-width: 768px) {
      /**
      * This is incredibly hacky: mark#2147915
      * We want to reduce the padding on mobile. By reducing the vertical padding
      * by double the amount, the size compensates and it looks the same.
      */
      padding: 0 6px;
    }
  }

  > .img-outer {
    position: relative;
    background-color: var(--color-background-dark);
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    display: inline-block;
    cursor: pointer;

    .cluster--rounded &,
    .cluster--album & {
      border-radius: 12px; // rounded corners
    }
    .cluster--album &,
    .cluster--circle & {
      height: unset;
      aspect-ratio: 1; // force square
    }
    .cluster--circle & {
      border-radius: 50%; // circle image
    }

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
      background: linear-gradient(0deg, rgba(0, 0, 0, 0.5) 10%, transparent 40%);

      .cluster.error &,
      .cluster--circle &,
      .cluster--album & {
        display: none;
      }
    }
  }
}
</style>
