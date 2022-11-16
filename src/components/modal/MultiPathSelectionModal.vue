<template>
  <Modal @close="close" v-if="show" size="small">
    <template #title>
      {{ title }}
    </template>

    <ul>
      <li v-for="(path, index) in paths" :key="index" class="path">
        {{ path }}

        <NcActions :inline="1">
          <NcActionButton
            :aria-label="t('memories', 'Remove')"
            @click="remove(index)"
          >
            {{ t("memories", "Remove") }}
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </li>
    </ul>

    <template #buttons>
      <NcButton @click="add" class="button" type="secondary">
        {{ t("memories", "Add Path") }}
      </NcButton>
      <NcButton @click="save" class="button" type="primary">
        {{ t("memories", "Save") }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";
import UserConfig from "../../mixins/UserConfig";

import Modal from "./Modal.vue";

import { getFilePickerBuilder } from "@nextcloud/dialogs";
import { NcActions, NcActionButton, NcButton } from "@nextcloud/vue";

import CloseIcon from "vue-material-design-icons/Close.vue";

@Component({
  components: {
    Modal,
    NcActions,
    NcActionButton,
    NcButton,
    CloseIcon,
  },
})
export default class Settings extends Mixins(UserConfig, GlobalMixin) {
  @Prop({ required: true }) title: string;

  private show = false;
  private paths: string[] = [];

  @Emit("close")
  public close(list: string[]) {
    this.show = false;
  }

  public open(paths: string[]) {
    this.paths = paths;
    this.show = true;
  }

  public save() {
    this.close(this.paths);
  }

  async chooseFolder(title: string, initial: string) {
    const picker = getFilePickerBuilder(title)
      .setMultiSelect(false)
      .setModal(true)
      .setType(1)
      .addMimeTypeFilter("httpd/unix-directory")
      .allowDirectories()
      .startAt(initial)
      .build();

    return await picker.pick();
  }

  public async add() {
    let newPath = await this.chooseFolder(
      this.t("memories", "Add a root to your timeline"),
      "/"
    );
    if (newPath === "") newPath = "/";
    this.paths.push(newPath);
  }

  public remove(index: number) {
    this.paths.splice(index, 1);
  }
}
</script>

<style lang="scss" scoped>
.path {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.1rem;
  padding-left: 10px;
  word-wrap: break-all;
}
</style>
