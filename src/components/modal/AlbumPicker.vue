<template>
  <div v-if="!showAlbumCreationForm" class="album-picker">
    <XLoadingIcon v-if="loadingAlbums" class="loading-icon" />

    <ul class="albums-container">
      <NcListItem
        v-for="album in albums"
        class="album"
        :key="album.album_id"
        :title="album.name"
        :aria-label="
          t('memories', 'Add selection to album {albumName}', {
            albumName: album.name,
          })
        "
        @click="pickAlbum(album)"
      >
        <template #icon>
          <XImg v-if="album.last_added_photo !== -1" class="album__image" :src="toCoverUrl(album.last_added_photo)" />
          <div v-else class="album__image album__image--placeholder">
            <ImageMultiple :size="32" />
          </div>
        </template>

        <template #subtitle>
          {{ getSubtitle(album) }}
        </template>

        <template #extra>
          <div v-if="album.has_file" class="check-circle-icon">
            <XImg :src="checkmarkIcon" />
          </div>
        </template>
        
      </NcListItem>
    </ul>

    <NcButton
      :aria-label="t('memories', 'Create a new album.')"
      class="new-album-button"
      type="tertiary"
      @click="showAlbumCreationForm = true"
    >
      <template #icon>
        <Plus />
      </template>
      {{ t('memories', 'Create new album') }}
    </NcButton>
  </div>

  <AlbumForm
    v-else
    :display-back-button="true"
    :title="t('memories', 'New album')"
    @back="showAlbumCreationForm = false"
    @done="albumCreatedHandler"
  />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { getCurrentUser } from '@nextcloud/auth';

import AlbumForm from './AlbumForm.vue';
import Plus from 'vue-material-design-icons/Plus.vue';
import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue';
import checkmarkIcon from '../../assets/checkmark.svg';

import axios from '@nextcloud/axios';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import { getPreviewUrl } from '../../services/utils/helpers';
import { IAlbum, IPhoto } from '../../types';
import { API } from '../../services/API';
import { PropType } from 'vue';

export default defineComponent({
  name: 'AlbumPicker',
  props: {
    /** List of pictures that are selected */
    photos: {
      type: Array as PropType<IPhoto[]>,
      required: true,
    },
  },
  components: {
    AlbumForm,
    Plus,
    ImageMultiple,
    NcButton,
    NcListItem,
  },

  data: () => ({
    showAlbumCreationForm: false,
    albums: [] as IAlbum[],
    loadingAlbums: true,
    photoId: -1,
    checkmarkIcon,
  }),

  mounted() {
    if (this.photos.length === 1) {
      // this only makes sense when we try to add single photo to albums
      this.photoId = this.photos[0].fileid;
    }
    this.loadAlbums();
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

    albumCreatedHandler() {
      this.showAlbumCreationForm = false;
      this.loadAlbums();
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

    async loadAlbums() {
      try {
        const res = await axios.get<IAlbum[]>(API.ALBUM_LIST(3, this.photoId));
        this.albums = res.data;
      } catch (e) {
        console.error(e);
      } finally {
        this.loadingAlbums = false;
      }
    },

    pickAlbum(album: IAlbum) {
      this.$emit('select', album);
    },
  },
});
</script>

<style lang="scss" scoped>
.album-picker {
  h2 {
    display: flex;
    align-items: center;
    height: 60px;

    .loading-icon {
      margin-left: 32px;
    }
  }

  .albums-container {
    min-height: 150px;
    max-height: 350px;
    overflow-x: scroll;
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

    .check-circle-icon {
      border-radius: 50%;
      background-color: rgba(0, 255, 0, 0.1882352941);
      height: 34px;
      width: 34px;
      display: flex;
      align-items: center;
      justify-content: center;

      & img {
        width: 50%;
        height: 50%;
      }
    }
  }

  .new-album-button {
    margin-top: 32px;
  }
}
</style>
