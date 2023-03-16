<template>
  <router-link
    draggable="false"
    class="tag fill-block"
    :class="{ face, error }"
    :to="target"
    @click.native="openTag(data)"
  >
    <div class="bbl">
      <NcCounterBubble> {{ data.count }} </NcCounterBubble>
    </div>
    <div class="name">
      {{ title }}
      <span class="subtitle" v-if="subtitle"> {{ subtitle }} </span>
    </div>

    <div class="previews fill-block" ref="previews">
      <div class="img-outer">
        <XImg
          draggable="false"
          class="fill-block"
          :class="{ error }"
          :src="previewUrl"
          @error="failed"
        />
        <div v-if="title || subtitle" class="overlay fill-block" />
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { defineComponent, PropType } from "vue";

import { IAlbum, ITag } from "../../types";
import { getPreviewUrl } from "../../services/FileUtils";
import { getCurrentUser } from "@nextcloud/auth";

import NcCounterBubble from "@nextcloud/vue/dist/Components/NcCounterBubble";

import { constants } from "../../services/Utils";
import { API } from "../../services/API";

export default defineComponent({
  name: "Tag",
  components: {
    NcCounterBubble,
  },

  props: {
    data: {
      type: Object as PropType<ITag>,
      required: true,
    },
    noNavigate: {
      type: Boolean,
      default: false,
    },
  },

  computed: {
    previewUrl() {
      if (this.album) {
        const mock = { fileid: this.album.last_added_photo, etag: "", flag: 0 };
        return getPreviewUrl(mock, true, 512);
      }

      if (this.face) {
        return API.FACE_PREVIEW(this.faceApp, this.face.fileid);
      }

      if (this.place) {
        return API.PLACE_PREVIEW(this.place.fileid);
      }

      return API.TAG_PREVIEW(this.data.name);
    },

    title() {
      if (this.tag) {
        return this.t("recognize", this.tag.name);
      }

      return this.data.name;
    },

    subtitle() {
      if (this.album && this.album.user !== getCurrentUser()?.uid) {
        return `(${this.album.user})`;
      }

      return "";
    },

    tag() {
      return !this.face && !this.place && !this.album ? this.data : null;
    },

    face() {
      return this.data.flag & constants.c.FLAG_IS_FACE_RECOGNIZE ||
        this.data.flag & constants.c.FLAG_IS_FACE_RECOGNITION
        ? this.data
        : null;
    },

    faceApp() {
      return this.data.flag & constants.c.FLAG_IS_FACE_RECOGNITION
        ? "facerecognition"
        : "recognize";
    },

    place() {
      return this.data.flag & constants.c.FLAG_IS_PLACE ? this.data : null;
    },

    album() {
      return this.data.flag & constants.c.FLAG_IS_ALBUM
        ? <IAlbum>this.data
        : null;
    },

    /** Target URL to navigate to */
    target() {
      if (this.noNavigate) return {};

      if (this.album) {
        const user = this.album.user;
        const name = this.album.name;
        return { name: "albums", params: { user, name } };
      }

      if (this.face) {
        const name = this.face.name || this.face.fileid.toString();
        const user = this.face.user_id;
        return { name: this.faceApp, params: { name, user } };
      }

      if (this.place) {
        const id = this.place.fileid.toString();
        const placeName = this.place.name || id;
        const name = `${id}-${placeName}`;
        return { name: "places", params: { name } };
      }

      return { name: "tags", params: { name: this.data.name } };
    },

    error() {
      return (
        Boolean(this.data.flag & this.c.FLAG_LOAD_FAIL) ||
        Boolean(this.album && this.album.last_added_photo <= 0)
      );
    },
  },
  methods: {
    /**
     * Open tag event
     * Unless noNavigate is set, the tag will be opened
     */
    openTag(tag: ITag) {
      this.$emit("open", tag);
    },

    /** Mark as loading failed */
    failed() {
      this.data.flag |= this.c.FLAG_LOAD_FAIL;
    },
  },
});
</script>

<style lang="scss" scoped>
.tag,
.name,
.bubble,
img {
  cursor: pointer;
}

// Get rid of color of the bubble
.tag .bbl :deep .counter-bubble__counter {
  color: unset !important;
}

.name {
  z-index: 100;
  position: absolute;
  bottom: 7%;
  width: 100%;
  color: white;
  padding: 0 5%;
  text-align: center;
  font-size: 1em;
  word-wrap: break-word;
  text-overflow: ellipsis;
  line-height: 1.2em;

  > .subtitle {
    font-size: 0.7em;
    margin-top: 2px;
    display: block;
  }

  .tag.error > & {
    color: unset;
  }

  @media (max-width: 768px) {
    font-size: 0.9em;
  }
}

.bbl {
  z-index: 100;
  position: absolute;
  top: 6px;
  right: 6px;
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;

  > .img-outer {
    position: relative;
    background-color: var(--color-background-dark);
    border-radius: 10px;
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    display: inline-block;
    cursor: pointer;

    > img {
      object-fit: cover;
      padding: 0;
      cursor: pointer;
      &.error {
        display: none;
      }
    }

    > .overlay {
      pointer-events: none;
      position: absolute;
      top: 0;
      left: 0;
      background: linear-gradient(
        0deg,
        rgba(0, 0, 0, 0.7) 10%,
        transparent 35%
      );
    }
  }
}
</style>
