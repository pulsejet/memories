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
      {{ data.name }}
      <span class="subtitle" v-if="subtitle"> {{ subtitle }} </span>
    </div>

    <div class="previews fill-block" ref="previews">
      <div class="img-outer">
        <img
          draggable="false"
          class="fill-block"
          :class="{ error }"
          :src="previewUrl"
          @error="data.flag |= c.FLAG_LOAD_FAIL"
        />
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { Component, Prop, Mixins, Emit } from "vue-property-decorator";
import { IAlbum, ITag } from "../../types";
import { getPreviewUrl } from "../../services/FileUtils";
import { getCurrentUser } from "@nextcloud/auth";

import NcCounterBubble from "@nextcloud/vue/dist/Components/NcCounterBubble";

import GlobalMixin from "../../mixins/GlobalMixin";
import { constants } from "../../services/Utils";
import { API } from "../../services/API";

@Component({
  components: {
    NcCounterBubble,
  },
})
export default class Tag extends Mixins(GlobalMixin) {
  @Prop() data: ITag;
  @Prop() noNavigate: boolean;

  /**
   * Open tag event
   * Unless noNavigate is set, the tag will be opened
   */
  @Emit("open")
  openTag(tag: ITag) {}

  get previewUrl() {
    if (this.face) {
      return API.FACE_PREVIEW(this.face.fileid);
    }

    if (this.album) {
      const mock = { fileid: this.album.last_added_photo, etag: "", flag: 0 };
      return getPreviewUrl(mock, true, 512);
    }

    return API.TAG_PREVIEW(this.data.name);
  }

  get subtitle() {
    if (this.album && this.album.user !== getCurrentUser()?.uid) {
      return `(${this.album.user})`;
    }

    return "";
  }

  get face() {
    return this.data.flag & constants.c.FLAG_IS_FACE ? this.data : null;
  }

  get album() {
    return this.data.flag & constants.c.FLAG_IS_ALBUM
      ? <IAlbum>this.data
      : null;
  }

  /** Target URL to navigate to */
  get target() {
    if (this.noNavigate) return {};

    if (this.face) {
      const name = this.face.name || this.face.fileid.toString();
      const user = this.face.user_id;
      return { name: "people", params: { name, user } };
    }

    if (this.album) {
      const user = this.album.user;
      const name = this.album.name;
      return { name: "albums", params: { user, name } };
    }

    return { name: "tags", params: { name: this.data.name } };
  }

  get error() {
    return (
      Boolean(this.data.flag & this.c.FLAG_LOAD_FAIL) ||
      Boolean(this.album && this.album.last_added_photo <= 0)
    );
  }
}
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
  top: 50%;
  width: 100%;
  transform: translateY(-50%);
  color: white;
  padding: 0 5%;
  text-align: center;
  font-size: 1.1em;
  word-wrap: break-word;
  text-overflow: ellipsis;
  line-height: 1em;

  > .subtitle {
    font-size: 0.7em;
    margin-top: 2px;
    display: block;
  }

  .tag.face > & {
    top: unset;
    bottom: 10%;
    transform: unset;
  }

  .tag.error > & {
    color: unset;
  }

  @media (max-width: 768px) {
    font-size: 0.95em;
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
      filter: brightness(60%);
      cursor: pointer;
      transition: filter 0.2s ease-in-out;

      &.error {
        display: none;
      }
      .tag:hover & {
        filter: brightness(100%);
      }
    }
  }
}
</style>