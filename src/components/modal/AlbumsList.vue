<template>
  <ul class="albums-container">
    <NcListItem
      v-for="album in albums"
      class="album"
      :key="album.album_id"
      :title="album.name"
      :aria-label="album.name"
      @click.prevent="$emit('click', album)"
    >
      <template #icon>
        <XImg v-if="album.last_added_photo !== -1" class="album__image" :src="toCoverUrl(album.last_added_photo)" />
        <div v-else class="album__image album__image--placeholder">
          <ImageMultipleIcon :size="32" />
        </div>
      </template>

      <template #subtitle>
        <div>
          {{ getSubtitle(album) }}
        </div>
      </template>

      <template #extra>
        <slot name="extra" :album="album"></slot>
      </template>
    </NcListItem>
  </ul>
</template>

<script lang="ts">
import { defineComponent, PropType } from 'vue';
import { getPreviewUrl } from '../../services/utils/helpers';

import { getCurrentUser } from '@nextcloud/auth';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import type { IAlbum, IPhoto } from '../../types';

import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';

export default defineComponent({
  name: 'AlbumsList',
  components: {
    NcListItem,
    NcButton,

    ImageMultipleIcon,
  },

  props: {
    albums: {
      type: Array as PropType<IAlbum[]>,
      required: true,
    },
  },

  methods: {
    toCoverUrl(fileId: string | number) {
      return getPreviewUrl({
        photo: {
          fileid: Number(fileId),
        } as IPhoto,
        sqsize: 256,
      });
    },

    getSubtitle(album: IAlbum) {
      let text = this.n('memories', '%n item', '%n items', album.count);

      if (album.user !== getCurrentUser()?.uid) {
        text +=
          ' / ' +
          this.t('memories', 'shared by {owner}', {
            owner: album.user_display || album.user,
          });
      }

      return text;
    },
  },
});
</script>

<style lang="scss" scoped>
.albums-container {
  overflow-x: hidden;
  padding: 2px;

  .album {
    :deep .list-item {
      box-sizing: border-box;
      display: flex;
    }

    :deep .list-item-content__wrapper {
      flex-grow: 1;
    }

    :deep .line-one__title {
      font-weight: 500;
    }

    &__image {
      width: auto;
      height: 100%;
      aspect-ratio: 1/1;
      object-fit: cover;
      border-radius: 50%;
      margin-right: 5px;

      &--placeholder {
        background: var(--color-primary-light);

        :deep .material-design-icon {
          width: 100%;
          height: 100%;

          .material-design-icon__svg {
            fill: var(--color-primary);
          }
        }
      }
    }
  }
}
</style>
