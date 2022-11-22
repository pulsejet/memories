<template>
  <div class="outer" v-if="detail">
    <div class="photo" v-for="photo of detail" :key="photo.fileid">
      <Tag :data="photo" :noNavigate="true" @open="clickFace" />
    </div>
  </div>
  <div v-else>
    {{ t("memories", "Loading …") }}
  </div>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Watch } from "vue-property-decorator";
import { IPhoto, ITag } from "../../types";
import Tag from "../frame/Tag.vue";

import GlobalMixin from "../../mixins/GlobalMixin";
import * as dav from "../../services/DavRequests";

@Component({
  components: {
    Tag,
  },
})
export default class FaceMergeModal extends Mixins(GlobalMixin) {
  private user: string = "";
  private name: string = "";
  private detail: IPhoto[] | null = null;

  @Emit("close")
  public close() {}

  @Watch("$route")
  async routeChange(from: any, to: any) {
    this.refreshParams();
  }

  mounted() {
    this.refreshParams();
  }

  public async refreshParams() {
    this.user = this.$route.params.user || "";
    this.name = this.$route.params.name || "";
    this.detail = null;

    let data = [];
    let flags = this.c.FLAG_IS_TAG;
    if (this.$route.name === 'people') {
      data = await dav.getPeopleRecognizeData();
      flags |= this.c.FLAG_IS_FACE_RECOGNIZE;
    } else {
      data = await dav.getPeopleFacerecognionData();
      flags |= this.c.FLAG_IS_FACE_RECOGNITION;
    }
    let detail = data[0].detail;
    detail.forEach((photo: IPhoto) => {
      photo.flag = flags;
    });
    detail = detail.filter((photo: ITag) => {
      const pname = photo.name || photo.fileid.toString();
      return photo.user_id !== this.user || pname !== this.name;
    });

    this.detail = detail;
  }

  @Emit("select")
  public async clickFace(face: ITag) {}
}
</script>

<style lang="scss" scoped>
.outer {
  width: 100%;
  max-height: calc(90vh - 80px - 4em);
  overflow-x: hidden;
  overflow-y: auto;
}
.photo {
  display: inline-block;
  position: relative;
  cursor: pointer;
  vertical-align: top;
  font-size: 0.8em;

  max-width: 120px;
  width: calc(33.33%);
  aspect-ratio: 1/1;
}
</style>