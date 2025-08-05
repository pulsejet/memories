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
        :name="t('memories', 'Reduced Size')"
        :bold="false"
        @click.prevent="shareLowRes()"
      >
        <template #icon>
          <PhotoIcon class="avatar" :size="24" />
        </template>

        <template #subname>
          {{ t('memories', 'Share in lower quality (small file size)') }}
        </template>
      </NcListItem>

      <NcListItem
        v-if="canShareNative && canShareHighRes"
        :name="t('memories', 'High Resolution')"
        :bold="false"
        @click.prevent="shareHighRes()"
      >
        <template #icon>
          <LargePhotoIcon class="avatar" :size="24" />
        </template>

        <template #subname>
          {{ t('memories', 'Share in high quality (large file size)') }}
        </template>
      </NcListItem>

      <NcListItem
        v-if="canShareNative && canDownload"
        :name="t('memories', 'Original File')"
        :bold="false"
        @click.prevent="shareOriginal()"
      >
        <template #icon>
          <FileIcon class="avatar" :size="24" />
        </template>

        <template #subname>
          {{ n('memories', 'Share the original file', 'Share the original files', photos?.length ?? 0) }}
        </template>
      </NcListItem>

      <NcListItem v-if="canShareLink" :name="t('memories', 'Public Link')" :bold="false" @click.prevent="shareLink">
        <template #icon>
          <LinkIcon class="avatar" :size="24" />
        </template>

        <template #subname>
          {{ t('memories', 'Share an external Nextcloud link') }}
        </template>
      </NcListItem>
    </ul>
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

const NcListItem = defineAsyncComponent(() => import('@nextcloud/vue/components/NcListItem'));

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
      return this.photos?.length === 1;
    },

    hasVideos(): boolean {
      return Boolean(this.photos?.some(utils.isVideo));
    },

    canDownload(): boolean {
      return this.photos?.every((p) => !p.imageInfo?.permissions?.includes('L')) ?? true;
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
      if (this.routeIsAlbums || !this.photos?.length || this.hasLocal) return false;

      // Check if all imageInfos are loaded (e.g. on viewer)
      // Then check if all images can be shared
      if (this.photos.every((p) => !!p.imageInfo)) {
        return Boolean(this.photos.every((p) => p.imageInfo?.permissions?.includes('S')));
      }

      // If imageInfos are not loaded, fail later
      return true;
    },

    hasLocal(): boolean {
      return Boolean(this.photos?.some(utils.isLocalPhoto));
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
      // Check if we have photos
      if (!this.photos) return;

      // Fill in image infos to get permissions and paths
      await this.l(async () => await dav.fillImageInfo(this.photos!));

      // Check if permissions allow sharing
      for (const photo of this.photos!) {
        // Error shown by fillImageInfo
        if (!photo.imageInfo) return;

        // Check if we can share this file
        if (!photo.imageInfo.permissions?.includes('S')) {
          const err = this.t('memories', 'Not allowed to share file: {name}', {
            name: photo.basename ?? photo.fileid,
          });
          showError(err);
          return;
        }
      }

      // Open node share modal if single file
      if (this.photos.length === 1) {
        const filename = this.photos[0].imageInfo!.filename;
        if (!filename) return;
        await this.close(); // wait till transition is done
        _m.modals.shareNodeLink(filename, true);
        return;
      }

      // Generate random alphanumeric string name for album
      const name = '.link-' + (Math.random() + 1).toString(36).substring(2);

      // Create hidden album if multiple files are selected
      await this.l(async () => {
        // Create album using WebDAV
        try {
          await dav.createAlbum(name, { rethrow: true });
        } catch (e) {
          showError(this.t('memories', 'Failed to create album for public link'));
          return null;
        }

        // Album is created, now add photos to it
        for await (const _ of dav.addToAlbum(utils.uid!, name, this.photos!)) {
          // do nothing
        }

        // Open album share modal
        await this.close(); // wait till transition is done
        await _m.modals.albumShare(utils.uid!, name, true);
      });
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
