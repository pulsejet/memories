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
import { defineComponent } from "vue";
import { IPhoto, ITag } from "../../types";
import Tag from "../frame/Tag.vue";

import * as dav from "../../services/DavRequests";

export default defineComponent({
  name: "FaceList",
  components: {
    Tag,
  },

  data() {
    return {
      user: "",
      name: "",
      detail: null as ITag[] | null,
    };
  },

  watch: {
    $route: async function (from: any, to: any) {
      this.refreshParams();
    },
  },

  mounted() {
    this.refreshParams();
  },

  methods: {
    close() {
      this.$emit("close");
    },

    async refreshParams() {
      this.user = <string>this.$route.params.user || "";
      this.name = <string>this.$route.params.name || "";
      this.detail = null;

      let data = [];
      let flags = this.c.FLAG_IS_TAG;
      if (this.$route.name === "recognize") {
        data = await dav.getPeopleData("recognize");
        flags |= this.c.FLAG_IS_FACE_RECOGNIZE;
      } else {
        data = await dav.getPeopleData("facerecognition");
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
    },

    async clickFace(face: ITag) {
      this.$emit("select", face);
    },
  },
});
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