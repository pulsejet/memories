<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t("memories", "Rename person") }}
    </template>

    <div class="fields">
      <NcTextField
        :value.sync="name"
        class="field"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        @keypress.enter="save()"
      />
    </div>

    <template #buttons>
      <NcButton @click="save" class="button" type="primary">
        {{ t("memories", "Update") }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Watch } from "vue-property-decorator";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
const NcTextField = () => import("@nextcloud/vue/dist/Components/NcTextField");

import { showError } from "@nextcloud/dialogs";
import { getCurrentUser } from "@nextcloud/auth";
import Modal from "./Modal.vue";
import GlobalMixin from "../../mixins/GlobalMixin";
import client from "../../services/DavClient";

@Component({
  components: {
    NcButton,
    NcTextField,
    Modal,
  },
})
export default class FaceEditModal extends Mixins(GlobalMixin) {
  private user: string = "";
  private name: string = "";
  private oldName: string = "";
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

  @Watch("$route")
  async routeChange(from: any, to: any) {
    this.refreshParams();
  }

  mounted() {
    this.refreshParams();
  }

  public refreshParams() {
    this.user = this.$route.params.user || "";
    this.name = this.$route.params.name || "";
    this.oldName = this.$route.params.name || "";
  }

  public async save() {
    try {
      await client.moveFile(
        `/recognize/${this.user}/faces/${this.oldName}`,
        `/recognize/${this.user}/faces/${this.name}`
      );
      this.$router.push({
        name: "people",
        params: { user: this.user, name: this.name },
      });
      this.close();
    } catch (error) {
      console.log(error);
      showError(
        this.t("photos", "Failed to rename {oldName} to {name}.", {
          oldName: this.oldName,
          name: this.name,
        })
      );
    }
  }
}
</script>

<style lang="scss" scoped>
.fields {
  margin-top: 8px;
}
</style>