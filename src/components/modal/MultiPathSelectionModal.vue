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
import { defineComponent } from "vue";

import Modal from "./Modal.vue";

import { getFilePickerBuilder } from "@nextcloud/dialogs";
import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";
import NcButton from "@nextcloud/vue/dist/Components/NcButton";

import CloseIcon from "vue-material-design-icons/Close.vue";

export default defineComponent({
  name: "MultiPathSelectionModal",
  components: {
    Modal,
    NcActions,
    NcActionButton,
    NcButton,
    CloseIcon,
  },

  props: {
    title: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      show: false,
      paths: [] as string[],
    };
  },

  methods: {
    close(list: string[]) {
      this.show = false;
      this.$emit("close", list);
    },

    open(paths: string[]) {
      this.paths = paths;
      this.show = true;
    },

    save() {
      this.close(this.paths);
    },

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
    },

    async add() {
      let newPath = await this.chooseFolder(
        this.t("memories", "Add a root to your timeline"),
        "/"
      );
      if (newPath === "") newPath = "/";
      this.paths.push(newPath);
    },

    remove(index: number) {
      this.paths.splice(index, 1);
    },
  },
});
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
