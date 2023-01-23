<template>
  <Modal v-if="show" @close="close">
    <template #title>
      {{ t("memories", "Edit EXIF Data") }}
    </template>

    <template #buttons>
      <NcButton
        @click="save"
        class="button"
        type="error"
        v-if="exif"
        :disabled="processing"
      >
        {{ t("memories", "Update Exif") }}
      </NcButton>
    </template>

    <div v-if="exif">
      <div class="fields">
        <NcTextField
          v-for="field of fields"
          :key="field.field"
          :value.sync="exif[field.field]"
          class="field"
          :label="field.label"
          :label-visible="true"
          :placeholder="field.label"
        />
      </div>
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { IPhoto } from "../../types";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
const NcTextField = () => import("@nextcloud/vue/dist/Components/NcTextField");

import { showError } from "@nextcloud/dialogs";
import { emit } from "@nextcloud/event-bus";
import axios from "@nextcloud/axios";
import { translate as t } from "@nextcloud/l10n";

import Modal from "./Modal.vue";
import { API } from "../../services/API";

export default defineComponent({
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  data: () => ({
    photo: null as IPhoto,
    show: false,
    exif: null as any,
    processing: false,
    fields: [
      {
        field: "Title",
        label: t("memories", "Title"),
      },
      {
        field: "Description",
        label: t("memories", "Description"),
      },
      {
        field: "DateTimeOriginal",
        label: t("memories", "Date Taken"),
      },
      {
        field: "Label",
        label: t("memories", "Label"),
      },
      {
        field: "Make",
        label: t("memories", "Camera Make"),
      },
      {
        field: "Model",
        label: t("memories", "Camera Model"),
      },
      {
        field: "LensModel",
        label: t("memories", "Lens Model"),
      },
      {
        field: "Copyright",
        label: t("memories", "Copyright"),
      },
    ],
  }),

  methods: {
    emitRefresh(val: boolean) {
      this.$emit("refresh", val);
    },

    async open(photo: IPhoto) {
      this.show = true;
      const res = await axios.get(API.IMAGE_INFO(photo.fileid));
      if (!res.data?.exif) return;

      const exif: any = {};
      for (const field of this.fields) {
        exif[field.field] = res.data.exif[field.field] || "";
      }

      this.photo = photo;
      this.exif = exif;
    },

    close() {
      this.exif = null;
      this.photo = null;
      this.show = false;
    },

    async saveOne() {
      try {
        // remove all null values from this.exif
        const exif = JSON.parse(JSON.stringify(this.exif));
        for (const key in exif) {
          if (!exif[key]) {
            delete exif[key];
          }
        }

        // Make PATCH request to update date
        this.processing = true;
        const fileid = this.photo.fileid;
        await axios.patch<any>(API.IMAGE_SETEXIF(fileid), {
          raw: exif,
        });
        emit("files:file:updated", { fileid });
        this.emitRefresh(true);
        this.close();
      } catch (e) {
        if (e.response?.data?.message) {
          showError(e.response.data.message);
        } else {
          showError(e);
        }
      } finally {
        this.processing = false;
      }
    },

    async save() {
      if (!this.photo) {
        return;
      }

      return await this.saveOne();
    },
  },
});
</script>

<style scoped lang="scss">
.fields {
  .field {
    margin-bottom: 8px;
  }
  :deep label {
    font-size: 0.8em;
    padding: 0 !important;
    padding-left: 5px !important;
  }
}
</style>
