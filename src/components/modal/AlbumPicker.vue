<!--
 - @copyright Copyright (c) 2022 Louis Chemineau <louis@chmn.me>
 -
 - @author Louis Chemineau <louis@chmn.me>
 -
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->
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
        <template slot="icon">
          <img
            v-if="album.last_added_photo !== -1"
            class="album__image"
            :src="album.last_added_photo | toCoverUrl"
          />
          <div v-else class="album__image album__image--placeholder">
            <ImageMultiple :size="32" />
          </div>
        </template>

        <template slot="subtitle">
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
import { Component, Emit, Mixins } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";
import { getCurrentUser } from "@nextcloud/auth";

import AlbumForm from "./AlbumForm.vue";
import Plus from "vue-material-design-icons/Plus.vue";
import ImageMultiple from "vue-material-design-icons/ImageMultiple.vue";

import { NcButton, NcListItem, NcLoadingIcon } from "@nextcloud/vue";
import { generateUrl } from "@nextcloud/router";
import { IAlbum } from "../../types";
import axios from "@nextcloud/axios";

@Component({
  components: {
    AlbumForm,
    Plus,
    ImageMultiple,
    NcButton,
    NcListItem,
    NcLoadingIcon,
  },
  filters: {
    toCoverUrl(fileId: string) {
      return generateUrl(
        `/apps/photos/api/v1/preview/${fileId}?x=${256}&y=${256}`
      );
    },
  },
})
export default class AlbumPicker extends Mixins(GlobalMixin) {
  private showAlbumCreationForm = false;
  private albums: IAlbum[] = [];
  private loadingAlbums = true;

  mounted() {
    this.loadAlbums();
  }

  albumCreatedHandler() {
    this.showAlbumCreationForm = false;
    this.loadAlbums();
  }

  getAlbumName(album: IAlbum) {
    if (album.user === getCurrentUser()?.uid) {
      return album.name;
    }
    return `${album.name} (${album.user})`;
  }

  async loadAlbums() {
    try {
      const res = await axios.get<IAlbum[]>(
        generateUrl("/apps/memories/api/albums?t=3")
      );
      this.albums = res.data;
    } catch (e) {
      console.error(e);
    } finally {
      this.loadingAlbums = false;
    }
  }

  @Emit("select")
  pickAlbum(album: IAlbum) {}
}
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