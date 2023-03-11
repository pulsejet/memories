<template>
  <Modal @close="close" size="normal" v-if="photo">
    <template #title>
      {{ t("memories", "Share File") }}
    </template>

    <div class="loading-icon fill-block" v-if="loading > 0">
      <NcLoadingIcon />
    </div>

    <ul class="options" v-else>
      <NcListItem
        v-if="canShareNative && !isVideo"
        :title="t('memories', 'Reduced Size')"
        :bold="false"
        @click.prevent="sharePreview()"
      >
        <template #icon>
          <PhotoIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ t("memories", "Share a lower resolution image preview") }}
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
          {{
            isVideo
              ? t("memories", "Share the video as a high-quality MOV")
              : t("memories", "Share the image as a high-quality JPEG")
          }}
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
          {{ t("memories", "Share the original image / video file") }}
        </template>
      </NcListItem>

      <NcListItem
        v-if="canShareLink"
        :title="t('memories', 'Public Link')"
        :bold="false"
        @click.prevent="shareLink()"
      >
        <template #icon>
          <LinkIcon class="avatar" :size="24" />
        </template>
        <template #subtitle>
          {{ t("memories", "Share an external Nextcloud link") }}
        </template>
      </NcListItem>
    </ul>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import { showError } from "@nextcloud/dialogs";
import { loadState } from "@nextcloud/initial-state";
import axios from "@nextcloud/axios";

import NcListItem from "@nextcloud/vue/dist/Components/NcListItem";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";
import Modal from "./Modal.vue";

import { IFileInfo, IPhoto } from "../../types";
import { getPreviewUrl } from "../../services/FileUtils";
import { API } from "../../services/API";
import * as dav from "../../services/DavRequests";

import PhotoIcon from "vue-material-design-icons/Image.vue";
import LargePhotoIcon from "vue-material-design-icons/ImageArea.vue";
import LinkIcon from "vue-material-design-icons/LinkVariant.vue";
import FileIcon from "vue-material-design-icons/File.vue";

// Is video transcoding enabled?
const config_noTranscode = loadState("memories", "notranscode", true);

export default defineComponent({
  name: "ShareModal",

  components: {
    NcListItem,
    NcLoadingIcon,
    Modal,

    PhotoIcon,
    LargePhotoIcon,
    LinkIcon,
    FileIcon,
  },

  data: () => {
    return {
      photo: null as IPhoto,
      loading: 0,
    };
  },

  created() {
    globalThis.sharePhoto = (photo: IPhoto) => {
      this.photo = photo;
    };
  },

  computed: {
    isVideo() {
      return (
        this.photo &&
        (this.photo.mimetype.startsWith("video/") ||
          this.photo.flag & this.c.FLAG_IS_VIDEO)
      );
    },

    canShareNative() {
      return "share" in navigator;
    },

    canShareHighRes() {
      return !this.isVideo || !config_noTranscode;
    },

    canShareLink() {
      return this.photo?.imageInfo?.permissions?.includes("S");
    },
  },

  methods: {
    close() {
      this.photo = null;
    },

    async l(cb: Function) {
      try {
        this.loading++;
        await cb();
      } finally {
        this.loading--;
      }
    },

    async getFileInfo() {
      if (this.$route.name.endsWith("-share")) {
        return this.photo as IFileInfo;
      }

      return (await dav.getFiles([this.photo]))[0];
    },

    async sharePreview() {
      const src = getPreviewUrl(this.photo, false, 2048);
      this.shareWithHref(src, true);
    },

    async shareHighRes() {
      const fileid = this.photo.fileid;
      const src = this.isVideo
        ? API.VIDEO_TRANSCODE(fileid, "max.mov")
        : API.IMAGE_JPEG(fileid);
      this.shareWithHref(src, !this.isVideo);
    },

    async shareOriginal() {
      this.shareWithHref(dav.getDownloadLink(this.photo));
    },

    async shareLink() {
      this.l(async () =>
        globalThis.shareNodeLink((await this.getFileInfo()).filename, true)
      );
      this.close();
    },

    async shareWithHref(href: string, replaceExt = false) {
      let fileInfo: IFileInfo, blob: Blob;
      await this.l(async () => {
        const res = await axios.get(href, { responseType: "blob" });
        blob = res.data;
        fileInfo = await this.getFileInfo();
      });

      if (!blob || !fileInfo) {
        showError(this.t("memories", "Failed to download and share file"));
        return;
      }

      let basename = fileInfo.originalBasename || fileInfo.basename;

      if (replaceExt) {
        // Fix basename extension
        let targetExts = [];
        if (blob.type === "image/png") {
          targetExts = ["png"];
        } else {
          targetExts = ["jpg", "jpeg"];
        }

        // Append extension if not found
        if (!targetExts.includes(basename.split(".").pop().toLowerCase())) {
          basename += "." + targetExts[0];
        }
      }

      const data = {
        files: [
          new File([blob], basename, {
            type: blob.type,
          }),
        ],
      };

      if (!(<any>navigator).canShare(data)) {
        showError(this.t("memories", "Cannot share this type of data"));
      }

      try {
        await navigator.share(data);
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
  :deep svg {
    width: 60px;
    height: 60px;
  }
}

ul.options {
  padding-top: 10px;
  padding-bottom: 5px;

  :deep .avatar {
    padding: 0 0.5em;
  }
}
</style>
