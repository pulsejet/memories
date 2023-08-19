<template>
  <div v-if="!showAlbumCreationForm" class="album-picker">
    <XLoadingIcon v-if="loadingAlbums" class="loading-icon centered" />

    <ul class="albums-container">
      <AlbumsList ref="albumsList" :albums="albums" :link="false" @click="toggleAlbumSelection">
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

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import * as dav from '../../services/DavRequests';
import { IAlbum, IPhoto } from '../../types';

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
    /** Initial selection */
    initSelection: new Set<IAlbum>(),
    /** Selected albums */
    selection: new Set<IAlbum>(),
    /** Deselected albums that were initially selected */
    deselection: new Set<IAlbum>(),
  }),

  mounted() {
    this.loadAlbums();
  },

  methods: {
    async albumCreatedHandler({ album }: { album: { basename: string } }) {
      this.showAlbumCreationForm = false;
      await this.loadAlbums(true);

      // select the newly created album
      const newAlbum = this.albums.find((a) => a.name === album.basename);
      if (newAlbum) {
        this.selection.add(newAlbum);
        this.forceUpdate();
      }
    },

    async loadAlbums(preserveSelection: boolean = false) {
      try {
        this.loadingAlbums = true;

        // FIXME: preserve deselection too; but then this is only
        // applicable for single photo selection ... at least for now
        const prevSel = new Set(Array.from(this.selection).map((a) => a.album_id));

        // get all albums
        this.albums = await dav.getAlbums();

        // reset selection
        this.initSelection = new Set();
        this.selection = new Set();
        this.deselection = new Set();

        // if only one photo is selected, get the albums of that photo
        const fileid = this.photos.length === 1 ? this.photos[0].fileid : 0;
        if (fileid) {
          const selIds = new Set((await dav.getAlbums(1, fileid)).map((a) => a.album_id));
          this.initSelection = new Set(this.albums.filter((a) => selIds.has(a.album_id)));
          this.selection = new Set(this.initSelection);
        }

        // restore selection
        if (preserveSelection) {
          this.albums.filter((a) => prevSel.has(a.album_id)).forEach(this.selection.add, this.selection);
        }
      } catch (e) {
        console.error(e);
      } finally {
        this.loadingAlbums = false;
        this.forceUpdate();
      }
    },

    toggleAlbumSelection(album: IAlbum) {
      if (this.disabled) return;

      if (this.selection.has(album)) {
        this.selection.delete(album);

        // deselection only if originally selected
        if (this.initSelection.has(album)) {
          this.deselection.add(album);
        }
      } else {
        this.selection.add(album);
        this.deselection.delete(album);
      }

      this.forceUpdate();
    },

    submit() {
      this.$emit('select', Array.from(this.selection), Array.from(this.deselection));
    },

    forceUpdate() {
      this.$forceUpdate(); // sets do not trigger reactivity
      (<any>this.$refs.albumsList)?.$forceUpdate();
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
