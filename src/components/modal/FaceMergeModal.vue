<template>
  <Modal @close="close" size="large" v-if="show">
    <template #title>
      {{
        t("memories", "Merge {name} with person", { name: $route.params.name })
      }}
    </template>

    <div class="outer">
      <FaceList @select="clickFace" />

      <div v-if="procesingTotal > 0" class="info-pad">
        {{
          t("memories", "Processing … {n}/{m}", {
            n: processing,
            m: procesingTotal,
          })
        }}
      </div>
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" type="error">
        {{ t("memories", "Cancel") }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins } from "vue-property-decorator";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
import NcTextField from "@nextcloud/vue/dist/Components/NcTextField";

import { showError } from "@nextcloud/dialogs";
import { getCurrentUser } from "@nextcloud/auth";
import { IFileInfo, ITag } from "../../types";
import Tag from "../frame/Tag.vue";
import FaceList from "./FaceList.vue";

import Modal from "./Modal.vue";
import GlobalMixin from "../../mixins/GlobalMixin";
import client from "../../services/DavClient";
import * as dav from "../../services/DavRequests";

@Component({
  components: {
    NcButton,
    NcTextField,
    Modal,
    Tag,
    FaceList,
  },
})
export default class FaceMergeModal extends Mixins(GlobalMixin) {
  private processing = 0;
  private procesingTotal = 0;
  private show = false;

  @Emit("close")
  public close() {
    this.show = false;
  }

  public open() {
    const user = this.$route.params.user || "";
    if (this.$route.params.user !== getCurrentUser()?.uid) {
      showError(
        this.t("memories", 'Only user "{user}" can update this person', {
          user,
        })
      );
      return;
    }
    this.show = true;
  }

  public async clickFace(face: ITag) {
    const user = this.$route.params.user || "";
    const name = this.$route.params.name || "";

    const newName = face.name || face.fileid.toString();
    if (
      !confirm(
        this.t(
          "memories",
          "Are you sure you want to merge {name} with {newName}?",
          { name, newName }
        )
      )
    ) {
      return;
    }

    try {
      // Get all files for current face
      let res = (await client.getDirectoryContents(
        `/recognize/${user}/faces/${name}`,
        { details: true }
      )) as any;
      let data: IFileInfo[] = res.data;
      this.procesingTotal = data.length;

      // Don't try too much
      let failures = 0;

      // Create move calls
      const calls = data.map((p) => async () => {
        // Short circuit if we have too many failures
        if (failures === 10) {
          showError(this.t("memories", "Too many failures, aborting"));
          failures++;
        }
        if (failures >= 10) return;

        // Move to new face with webdav
        try {
          await client.moveFile(
            `/recognize/${user}/faces/${name}/${p.basename}`,
            `/recognize/${face.user_id}/faces/${newName}/${p.basename}`
          );
        } catch (e) {
          console.error(e);
          showError(this.t("memories", "Error while moving {basename}", p));
          failures++;
        } finally {
          this.processing++;
        }
      });
      for await (const _ of dav.runInParallel(calls, 10)) {
        // nothing to do
      }

      // Go to new face
      if (failures === 0) {
        this.$router.push({
          name: "people",
          params: { user: face.user_id, name: newName },
        });
        this.close();
      }
    } catch (error) {
      console.error(error);
      showError(this.t("photos", "Failed to move {name}.", { name }));
    }
  }
}
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
.info-pad {
  margin-top: 6px;
  margin-bottom: 2px;
}
</style>