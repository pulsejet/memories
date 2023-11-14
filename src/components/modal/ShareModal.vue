<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      {{ n('memories', 'Share File', 'Share Files', photos?.length ?? 0) }}
    </template>

    <div class="loading-icon fill-block" v-if="loading > 0">
      <XLoadingIcon />
    </div>

    <ul class="options" v-else>
      <NcListItem
        v-if="canShareNative && canShareLowRes"
        :title="t('memories', 'Reduced Size')"
        :bold="false"
        @click.prevent="shareLowRes()"
      >
        <template #icon>
          <PhotoIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ t('memories', 'Share in lower quality (small file size)') }}
        </template>
      </NcListItem>

      <NcListItem
        v-if="canShareNative && canShareHighRes"
        :title="t('memories', 'High Resolution')"
        :bold="false"
        @click.prevent="shareHighRes()"
      >
        <template #icon>
          <LargePhotoIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ t('memories', 'Share in high quality (large file size)') }}
        </template>
      </NcListItem>

      <NcListItem
        v-if="canShareNative"
        :title="t('memories', 'Original File')"
        :bold="false"
        @click.prevent="shareOriginal()"
      >
        <template #icon>
          <FileIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ n('memories', 'Share the original file', 'Share the original files', photos?.length ?? 0) }}
        </template>
      </NcListItem>

      <NcListItem v-if="canShareLink" :title="t('memories', 'Public Link')" :bold="false" @click.prevent="shareLink()">
        <template #icon>
          <LinkIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ t('memories', 'Share an external Nextcloud link') }}
        </template>
      </NcListItem>
    </ul>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem');

import UserConfig from '@mixins/UserConfig';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import { API } from '@services/API';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import type { IPhoto } from '@typings';

import PhotoIcon from 'vue-material-design-icons/Image.vue';
import LargePhotoIcon from 'vue-material-design-icons/ImageArea.vue';
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue';
import FileIcon from 'vue-material-design-icons/File.vue';

export default defineComponent({
  name: 'ShareModal',

  components: {
    NcListItem,
    Modal,

    PhotoIcon,
    LargePhotoIcon,
    LinkIcon,
    FileIcon,
  },

  mixins: [UserConfig, ModalMixin],

  data: () => ({
    photos: null as IPhoto[] | null,
    loading: 0,
  }),

  created() {
    console.assert(!_m.modals.sharePhotos, 'ShareModal created twice');
    _m.modals.sharePhotos = this.open;
  },

  computed: {
    isSingle(): boolean {
      return this.photos?.length === 1 ?? false;
    },

    hasVideos(): boolean {
      return !!this.photos?.some(utils.isVideo);
    },

    canShareNative(): boolean {
      return 'share' in navigator || nativex.has();
    },

    canShareLowRes(): boolean {
      // Only allow transcoding videos if a single video is selected
      return !this.hasLocal && (!this.hasVideos || (!this.config.vod_disable && this.isSingle));
    },

    canShareHighRes(): boolean {
      // High-CPU operations only permitted for single node
      return this.isSingle && this.canShareLowRes;
    },

    canShareLink(): boolean {
      return !this.routeIsAlbums && !!this.photos?.every((p) => p?.imageInfo?.permissions?.includes('S'));
    },

    hasLocal(): boolean {
      return !!this.photos?.some(utils.isLocalPhoto);
    },
  },

  methods: {
    open(photos: IPhoto[]) {
      this.photos = photos;
      this.loading = 0;
      this.show = true;
    },

    cleanup() {
      this.show = false;
      this.photos = null;
    },

    async l<T>(cb: () => Promise<T>): Promise<T> {
      try {
        this.loading++;
        return await cb();
      } finally {
        this.loading--;
      }
    },

    async shareLowRes() {
      await this.shareWithHref(
        this.photos!.map((photo) => ({
          auid: String(), // no local
          href: utils.isVideo(photo)
            ? API.VIDEO_TRANSCODE(photo.fileid, '480p.mp4')
            : utils.getPreviewUrl({ photo, size: 2048 }),
        })),
      );
    },

    async shareHighRes() {
      await this.shareWithHref(
        this.photos!.map((photo) => ({
          auid: String(), // no local
          href: utils.isVideo(photo)
            ? API.VIDEO_TRANSCODE(photo.fileid, '1080p.mp4')
            : API.IMAGE_DECODABLE(photo.fileid, photo.etag),
        })),
      );
    },

    async shareOriginal() {
      await this.shareWithHref(
        this.photos!.map((photo) => ({
          auid: photo.auid ?? String(),
          href: dav.getDownloadLink(photo),
        })),
      );
    },

    async shareLink() {
      const fileInfo = await this.l(async () => (await dav.getFiles([this.photos![0]]))[0]);
      await this.close(); // wait till transition is done
      _m.modals.shareNodeLink(fileInfo.filename, true);
    },

    /**
     * Download a file and then share the blob.
     *
     * If a download object includes AUID then local download
     * is allowed when on NativeX.
     */
    async shareWithHref(
      objects: {
        auid: string;
        href: string;
      }[],
    ) {
      if (nativex.has()) {
        return await this.l(async () => nativex.shareBlobs(objects));
      }

      // Pull blobs in parallel
      const calls = objects.map((obj) => async () => {
        return await this.l(async () => {
          try {
            return await axios.get(obj.href, { responseType: 'blob' });
          } catch (e) {
            showError(this.t('memories', 'Failed to download file {href}', { href: obj.href }));
            return null;
          }
        });
      });

      // Get all blobs from parallel calls
      const files: File[] = [];
      for await (const responses of dav.runInParallel(calls, 8)) {
        for (const res of responses.filter(Boolean)) {
          const filename = res!.headers['content-disposition']?.match(/filename="(.+)"/)?.[1] ?? '';
          const blob = res!.data;
          files.push(new File([blob], filename, { type: blob.type }));
        }
      }
      if (!files.length) return;

      // Check if we can share this type of data
      if (!navigator.canShare({ files })) {
        showError(this.t('memories', 'Cannot share this type of data'));
      }

      try {
        await navigator.share({ files });
      } catch (e) {
        // Don't show this error because it's silly stuff
        // like "share canceled"
        console.error(e);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.loading-icon {
  min-height: 240px;
}

ul.options {
  padding-top: 10px;
  padding-bottom: 5px;

  :deep .avatar {
    padding: 0 0.5em;
  }

  @media (max-width: 512px) {
    font-size: 0.9em;
  }
}
</style>
