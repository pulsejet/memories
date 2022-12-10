<template>
  <div v-if="!showAlbumCreationForm" class="album-picker">
    <NcLoadingIcon v-if="loadingAlbums" class="loading-icon" />

    <ul class="albums-container">
      <NcListItem
        v-for="album in albums"
        :key="album.album_id"
        class="album"
        :title="getAlbumName(album)"
        :aria-label="
          t('photos', 'Add selection to album {albumName}', {
            albumName: getAlbumName(album),
          })
        "
        @click="pickAlbum(album)"
      >
        <template v-slot:icon="{}">
          <img
            v-if="album.last_added_photo !== -1"
            class="album__image"
            :src="toCoverUrl(album.last_added_photo)"
          />
          <div v-else class="album__image album__image--placeholder">
            <ImageMultiple :size="32" />
          </div>
        </template>

        <template v-slot:subtitle="{}">
          {{ n("photos", "%n item", "%n items", album.count) }}
          <!-- TODO: finish collaboration -->
          <!--⸱ {{ n('photos', 'Share with %n user', 'Share with %n users', album.isShared) }}-->
        </template>
      </NcListItem>
    </ul>

    <NcButton
      :aria-label="t('photos', 'Create a new album.')"
      class="new-album-button"
      type="tertiary"
      @click="showAlbumCreationForm = true"
    >
      <template #icon>
        <Plus />
      </template>
      {{ t("photos", "Create new album") }}
    </NcButton>
  </div>

  <AlbumForm
    v-else
    :display-back-button="true"
    :title="t('photos', 'New album')"
    @back="showAlbumCreationForm = false"
    @done="albumCreatedHandler"
  />
</template>

<script lang="ts">
import { defineComponent } from "vue";

import { getCurrentUser } from "@nextcloud/auth";

import AlbumForm from "./AlbumForm.vue";
import Plus from "vue-material-design-icons/Plus.vue";
import ImageMultiple from "vue-material-design-icons/ImageMultiple.vue";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";
const NcListItem = () => import("@nextcloud/vue/dist/Components/NcListItem");

import { getPreviewUrl } from "../../services/FileUtils";
import { IAlbum, IPhoto } from "../../types";
import axios from "@nextcloud/axios";
import { API } from "../../services/API";

export default defineComponent({
  name: "AlbumPicker",
  components: {
    AlbumForm,
    Plus,
    ImageMultiple,
    NcButton,
    NcListItem,
    NcLoadingIcon,
  },

  data: () => ({
    showAlbumCreationForm: false,
    albums: [] as IAlbum[],
    loadingAlbums: true,
  }),

  mounted() {
    this.loadAlbums();
  },

  methods: {
    toCoverUrl(fileId: string | number) {
      return getPreviewUrl(
        {
          fileid: Number(fileId),
        } as IPhoto,
        true,
        256
      );
    },

    albumCreatedHandler() {
      this.showAlbumCreationForm = false;
      this.loadAlbums();
    },

    getAlbumName(album: IAlbum) {
      if (album.user === getCurrentUser()?.uid) {
        return album.name;
      }
      return `${album.name} (${album.user})`;
    },

    async loadAlbums() {
      try {
        const res = await axios.get<IAlbum[]>(API.ALBUM_LIST());
        this.albums = res.data;
      } catch (e) {
        console.error(e);
      } finally {
        this.loadingAlbums = false;
      }
    },

    pickAlbum(album: IAlbum) {
      this.$emit("select", album);
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
        padding: 8px 16px;
        box-sizing: border-box;
      }

      &:not(:last-child) {
        margin-bottom: 16px;
      }

      &__image {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: var(--border-radius);

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

  .new-album-button {
    margin-top: 32px;
  }
}
</style>
