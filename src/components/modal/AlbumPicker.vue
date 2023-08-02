<template>
  <div v-if="!showAlbumCreationForm" class="album-picker">
    <XLoadingIcon v-if="loadingAlbums" class="loading-icon centered" />

    <ul class="albums-container">
      <AlbumsList ref="albumsList" :albums="albums" @click="toggleAlbumSelection">
        <template #extra="{ album }">
          <div
            class="check-circle-icon"
            :class="{
              'check-circle-icon--active': selection.has(album),
            }"
          >
            <CheckIcon :size="20" />
          </div>
        </template>
      </AlbumsList>
    </ul>

    <div class="actions">
      <NcButton
        :aria-label="t('memories', 'Create new album.')"
        :disabled="disabled"
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
        <NcButton
          class="new-album-button"
          type="primary"
          :aria-label="t('memories', 'Save changes')"
          :disabled="disabled"
          @click="submit"
        >
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
import { defineComponent, PropType } from 'vue';

import AlbumForm from './AlbumForm.vue';
import AlbumsList from './AlbumsList.vue';

import axios from '@nextcloud/axios';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import { IAlbum, IPhoto } from '../../types';
import { API } from '../../services/API';

import PlusIcon from 'vue-material-design-icons/Plus.vue';
import CheckIcon from 'vue-material-design-icons/Check.vue';

export default defineComponent({
  name: 'AlbumPicker',
  props: {
    /** List of pictures that are selected */
    photos: {
      type: Array as PropType<IPhoto[]>,
      required: true,
    },

    /** Disable controls */
    disabled: {
      type: Boolean,
      default: false,
    },
  },
  components: {
    AlbumForm,
    AlbumsList,
    NcButton,
    NcListItem,

    PlusIcon,
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
    albumCreatedHandler() {
      this.showAlbumCreationForm = false;
      this.loadAlbums();
    },

    async loadAlbums() {
      try {
        this.loadingAlbums = true;

        // this only makes sense when we try to add single photo to albums
        const fileid = this.photos.length === 1 ? this.photos[0].fileid : -1;

        // get albums, possibly for one photo
        const res = await axios.get<IAlbum[]>(API.ALBUM_LIST(3, fileid));
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
      if (this.disabled) return;

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
      (<any>this.$refs.albumsList)?.$forceUpdate();
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
