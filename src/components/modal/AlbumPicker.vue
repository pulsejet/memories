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
        @click.prevent="toggleAlbumSelection(album)"
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
          <div
            class="check-circle-icon"
            :class="{
              'check-circle-icon--active': selection.has(album),
            }"
          >
            <CheckIcon :size="20" />
          </div>
        </template>
      </NcListItem>
    </ul>

    <div class="actions">
      <NcButton
        :aria-label="t('memories', 'Create a new album.')"
        class="new-album-button"
        type="tertiary"
        @click="showAlbumCreationForm = true"
      >
        <template #icon>
          <PlusIcon />
        </template>
        {{ t('memories', 'Create new album') }}
      </NcButton>

      <div class="submit-btn-wrapper">
        <NcButton :aria-label="t('memories', 'Save')" class="new-album-button" type="primary" @click="submit">
          {{ t('memories', 'Save changes') }}
        </NcButton>
        <span class="remove-notice" v-if="deselection.size > 0">
          {{
            n('memories', 'Removed from {n} album', 'Removed from {n} albums', deselection.size, {
              n: this.deselection.size,
            })
          }}
        </span>
      </div>
    </div>
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

import axios from '@nextcloud/axios';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import { getPreviewUrl } from '../../services/utils/helpers';
import { IAlbum, IPhoto } from '../../types';
import { API } from '../../services/API';
import { PropType } from 'vue';

import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import CheckIcon from 'vue-material-design-icons/Check.vue';

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
    NcButton,
    NcListItem,

    PlusIcon,
    ImageMultipleIcon,
    CheckIcon,
  },

  data: () => ({
    showAlbumCreationForm: false,
    loadingAlbums: true,
    /** List of all albums */
    albums: [] as IAlbum[],
    /** All selected albums */
    selection: new Set<IAlbum>(),
    /** Deselected albums that were previously selected */
    deselection: new Set<IAlbum>(),
  }),

  mounted() {
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
        // this only makes sense when we try to add single photo to albums
        const photoId = this.photos.length === 1 ? this.photos[0].fileid : -1;

        // get albums, possibly for one photo
        const res = await axios.get<IAlbum[]>(API.ALBUM_LIST(3, photoId));
        this.albums = res.data;
        this.selection = new Set(this.albums.filter((album) => album.has_file));
        this.deselection = new Set();
      } catch (e) {
        console.error(e);
      } finally {
        this.loadingAlbums = false;
      }
    },

    toggleAlbumSelection(album: IAlbum) {
      if (this.selection.has(album)) {
        this.selection.delete(album);

        // deselection only if originally selected
        if (album.has_file) {
          this.deselection.add(album);
        }
      } else {
        this.selection.add(album);
        this.deselection.delete(album);
      }

      this.$forceUpdate(); // sets do not trigger reactivity
    },

    submit() {
      this.$emit('select', Array.from(this.selection), Array.from(this.deselection));
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
      border: 1px solid rgba($color: black, $alpha: 0.1);
      background-color: transparent;
      height: 34px;
      width: 34px;
      display: flex;
      align-items: center;
      justify-content: center;

      &--active {
        border: 1px solid var(--color-primary);
        background-color: var(--color-primary-default);
        color: var(--color-primary-text);
      }

      & img {
        width: 50%;
        height: 50%;
      }
    }
  }

  .new-album-button {
    margin-top: 32px;
  }

  .actions {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .submit-btn-wrapper {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
  }

  .remove-notice {
    font-size: small;
  }
}
</style>
