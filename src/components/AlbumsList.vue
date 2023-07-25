<template>
    <div class="fill-block wrapper">
      <div class="loading-icon fill-block" v-if="loadingAlbums">
        <XLoadingIcon />
      </div>
      <span class="empty-state" v-if="albums.length === 0">{{ t('memories', 'No albums') }}</span>
      <ul v-else class="albums-container">
        <NcListItem
          v-for="album in albums"
          class="album"
          :key="album.album_id"
          :title="album.name"
          @click="ignoreClick"
        >
          <template #icon>
            <XImg class="album__image" :src="toCoverUrl(album.last_added_photo)" />
          </template>

          <template #subtitle>
            {{ getSubtitle(album) }}
          </template>
          
        </NcListItem>
      </ul>
      <NcButton
        :aria-label="t('memories', 'Add to album.')"
        class="new-album-button"
        type="tertiary"
        @click="addToAlbum"
      >
        <template #icon>
          <Plus />
        </template>
        {{ t('memories', 'Add to album') }}
      </NcButton>
      <AddToAlbumModal ref="addToAlbumModal" @added="update" />
    </div>
  </template>
  
  <script lang="ts">
  import { defineComponent } from 'vue';
  import axios from '@nextcloud/axios';
  import { subscribe, unsubscribe } from '@nextcloud/event-bus';
  import { IAlbum, IPhoto } from '../types';
  import { API } from '../services/API';
  import NcButton from '@nextcloud/vue/dist/Components/NcButton';
  import Plus from 'vue-material-design-icons/Plus.vue';
  import AddToAlbumModal from './modal/AddToAlbumModal.vue';
  import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue';
  import { getPreviewUrl } from '../services/utils/helpers';
  import { getCurrentUser } from '@nextcloud/auth';
  
  const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');
  
  export default defineComponent({
    name: 'AlbumsList',
    components: {
      ImageMultiple,
      NcListItem,
      NcButton,
      Plus,
      AddToAlbumModal,
    },
  
    data: () => ({
      fileid: -1,
      albums: [] as IAlbum[],
      loadingAlbums: false,
    }),
  
    mounted() {
      subscribe('files:file:updated', this.handleFileUpdated);
    },
  
    beforeDestroy() {
      unsubscribe('files:file:updated', this.handleFileUpdated);
    },
  
    methods: {
      update(photoId: number){
        this.fileid = photoId;
        this.loadAlbums();
      },

      async loadAlbums() {
        try {
          this.loadingAlbums = true;
          const res = await axios.get<IAlbum[]>(API.ALBUM_LIST(3, this.fileid));
          this.albums = res.data.filter(album => album.has_file);
        } catch (e) {
          console.error(e);
        } finally {
          this.loadingAlbums = false;
        }
      },
  
      handleFileUpdated({ fileid }: { fileid: number }) {
        if (fileid && this.fileid === fileid) {
          this.update(this.fileid);
        }
      },

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

      ignoreClick(e) {
        e.preventDefault();
      },

      async addToAlbum() {
        (<any>this.$refs.addToAlbumModal).open([{
          fileid: this.fileid,
        }]);
      },
    },
  });
  </script>
  
  <style lang="scss" scoped>
    .loading-icon {
      height: 75%;
    }

    .wrapper {
      display: flex;
      flex-direction: column;
    }

    .albums-container {
      flex-grow: 1;
      overflow: auto;
    }

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
      }
    }

    .empty-state {
      width: 100%;
      flex-grow: 1;
      align-items: center;
      justify-content: center;
      display: flex;
    }
  
  </style>
  