<template>
  <router-link
    draggable="false"
    class="tag fill-block"
    :class="{
      hasPreview: previews.length > 0,
      onePreview: previews.length === 1,
      hasError: error,
      isFace: isFace,
    }"
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
      <div class="img-outer" v-for="info of previews" :key="info.fileid">
        <img
          draggable="false"
          class="fill-block"
          :class="{ error: info.flag & c.FLAG_LOAD_FAIL }"
          :key="'fpreview-' + info.fileid"
          :src="getPreviewUrl(info)"
          @error="info.flag |= c.FLAG_LOAD_FAIL"
        />
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { Component, Prop, Watch, Mixins, Emit } from "vue-property-decorator";
import { IAlbum, IPhoto, ITag } from "../../types";
import { generateUrl } from "@nextcloud/router";
import { getPhotosPreviewUrl, getPreviewUrl } from "../../services/FileUtils";
import { getCurrentUser } from "@nextcloud/auth";

import { NcCounterBubble } from "@nextcloud/vue";
import axios from "@nextcloud/axios";
import * as utils from "../../services/Utils";

import GlobalMixin from "../../mixins/GlobalMixin";
import { constants } from "../../services/Utils";

@Component({
  components: {
    NcCounterBubble,
  },
})
export default class Tag extends Mixins(GlobalMixin) {
  @Prop() data: ITag;
  @Prop() noNavigate: boolean;

  // Separate property because the one on data isn't reactive
  private previews: IPhoto[] = [];

  // Error occured fetching thumbs
  private error = false;

  // Smaller subtitle
  private subtitle = "";

  /**
   * Open tag event
   * Unless noNavigate is set, the tag will be opened
   */
  @Emit("open")
  openTag(tag: ITag) {}

  mounted() {
    this.refreshPreviews();
  }

  @Watch("data")
  dataChanged() {
    this.refreshPreviews();
  }

  getPreviewUrl(photo: IPhoto) {
    if (this.isFace) {
      return generateUrl(
        "/apps/memories/api/faces/preview/" + this.data.fileid
      );
    }

    if (this.isAlbum) {
      return getPhotosPreviewUrl(photo, true, 256);
    }

    return getPreviewUrl(photo, true, 256);
  }

  get isFace() {
    return this.data.flag & constants.c.FLAG_IS_FACE;
  }

  get isAlbum() {
    return this.data.flag & constants.c.FLAG_IS_ALBUM;
  }

  async refreshPreviews() {
    // Reset state
    this.error = false;
    this.subtitle = "";

    // Add dummy preview if face
    if (this.isFace) {
      this.previews = [{ fileid: 0, etag: "", flag: 0 }];
      return;
    }

    // Add preview from last photo if album
    if (this.isAlbum) {
      const album = this.data as IAlbum;
      if (album.last_added_photo > 0) {
        this.previews = [{ fileid: album.last_added_photo, etag: "", flag: 0 }];
      }
      if (album.user !== getCurrentUser()?.uid) {
        this.subtitle = `(${album.user})`;
      }
      return;
    }

    // Look for previews
    if (!this.data.previews) {
      try {
        const todayDayId = utils.dateToDayId(new Date());
        const url = generateUrl(
          `/apps/memories/api/tag-previews?tag=${this.data.name}`
        );
        const cacheUrl = `${url}&today=${todayDayId}`;
        const cache = await utils.getCachedData(cacheUrl);
        if (cache) {
          this.data.previews = cache as any;
        } else {
          const res = await axios.get(url);
          this.data.previews = res.data;

          // Cache only if >= 4 previews
          if (this.data.previews.length >= 4) {
            utils.cacheData(cacheUrl, res.data);
          }
        }
      } catch (e) {
        this.error = true;
        return;
      }
    }

    // Reset flag
    this.data.previews.forEach((p) => (p.flag = 0));

    // Get 4 or 1 preview(s)
    let data = this.data.previews;
    if (data.length < 4) {
      data = data.slice(0, 1);
    }
    this.previews = data;

    this.error = this.previews.length === 0;
  }

  /** Target URL to navigate to */
  get target() {
    if (this.noNavigate) return {};

    if (this.isFace) {
      const name = this.data.name || this.data.fileid.toString();
      const user = this.data.user_id;
      return { name: "people", params: { name, user } };
    }

    if (this.isAlbum) {
      const user = (<IAlbum>this.data).user;
      const name = this.data.name;
      return { name: "albums", params: { user, name } };
    }

    return { name: "tags", params: { name: this.data.name } };
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
  font-size: 1.2em;
  word-wrap: break-word;
  text-overflow: ellipsis;
  line-height: 1em;

  > .subtitle {
    font-size: 0.7em;
    margin-top: 2px;
    display: block;
  }

  .isFace > & {
    top: unset;
    bottom: 10%;
    transform: unset;
  }
}

.bbl {
  z-index: 100;
  position: absolute;
  top: 6px;
  right: 5px;
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;
  @media (max-width: 768px) {
    padding: 1px;
  }

  .tag:not(.hasPreview) & {
    background-color: #444;
    background-clip: content-box;
  }

  > .img-outer {
    background-color: var(--color-background-dark);
    padding: 0;
    margin: 0;
    width: 50%;
    height: 50%;
    overflow: hidden;
    display: inline-block;
    cursor: pointer;

    .tag.onePreview > & {
      width: 100%;
      height: 100%;
    }

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