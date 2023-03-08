<template>
  <Modal v-if="show" @close="close">
    <template #title>
      {{ t("memories", "Edit Metadata") }}
    </template>

    <template #buttons>
      <NcButton
        @click="save"
        class="button"
        type="error"
        v-if="photos"
        :disabled="processing"
      >
        {{ t("memories", "Save") }}
      </NcButton>
    </template>

    <div v-if="photos">
      <div class="title-text">
        {{ t("memories", "Date / Time") }}
      </div>
      <EditDate ref="editDate" :photos="photos" />

      <div class="title-text">
        {{ t("memories", "EXIF Fields") }}
      </div>
      <EditExif ref="editExif" :photos="photos" />
    </div>

    <div v-if="processing">
      <NcProgressBar :value="progress" :error="true" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { IPhoto } from "../../types";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
const NcTextField = () => import("@nextcloud/vue/dist/Components/NcTextField");
const NcProgressBar = () =>
  import("@nextcloud/vue/dist/Components/NcProgressBar");
import Modal from "./Modal.vue";

import EditExif from "./EditExif.vue";
import EditDate from "./EditDate.vue";

import { showError } from "@nextcloud/dialogs";
import { emit } from "@nextcloud/event-bus";
import axios from "@nextcloud/axios";

import * as dav from "../../services/DavRequests";
import { API } from "../../services/API";

export default defineComponent({
  components: {
    NcButton,
    NcTextField,
    NcProgressBar,
    Modal,

    EditExif,
    EditDate,
  },

  data: () => ({
    photos: null as IPhoto[],
    show: false,
    processing: false,
    progress: 0,
    state: 0,
  }),

  methods: {
    emitRefresh(val: boolean) {
      this.$emit("refresh", val);
    },

    async open(photos: IPhoto[]) {
      const state = (this.state = Math.random());
      this.show = true;
      this.processing = true;

      let done = 0;
      this.progress = 0;

      // Load metadata for all photos
      const calls = photos.map((p) => async () => {
        try {
          const res = await axios.get<any>(API.IMAGE_INFO(p.fileid));

          // Validate response
          p.imageInfo = null;
          if (typeof res.data.datetaken !== "number") {
            console.error("Invalid date for", p.fileid);
            return;
          }
          p.datetaken = res.data.datetaken * 1000;
          p.imageInfo = res.data;
        } catch (error) {
          console.error("Failed to get date info for", p.fileid, error);
        } finally {
          done++;
          this.progress = Math.round((done * 100) / photos.length);
        }
      });

      for await (const _ of dav.runInParallel(calls, 8)) {
        // nothing to do
      }

      // Check if already quit
      if (!this.show || this.state !== state) return;

      // Check for anything invalid
      const invalid = photos.filter((p) => !p.imageInfo);
      if (invalid.length > 0) {
        showError(
          this.t("memories", "Failed to load metadata for {n} photos.", {
            n: invalid.length,
          })
        );
        photos = photos.filter((p) => p.imageInfo);
      }

      this.photos = photos;
      this.processing = false;
    },

    close() {
      this.photos = null;
      this.show = false;
    },

    async save() {
      // Perform validation
      try {
        (<any>this.$refs.editDate).validate();
      } catch (e) {
        console.error(e);
        showError(e);
        return;
      }

      // Get exif fields diff
      const exifChanges = (<any>this.$refs.editExif).changes();

      // Start processing
      let done = 0;
      this.progress = 0;
      this.processing = true;

      // Update exif fields
      const calls = this.photos.map((p) => async () => {
        try {
          const fileid = p.fileid;

          // Basic EXIF fields
          const raw = JSON.parse(JSON.stringify(exifChanges));

          // Date
          const date = (<any>this.$refs.editDate).result(p);
          if (date) {
            raw.DateTimeOriginal = date;
          }

          if (Object.keys(raw).length === 0) {
            console.log("No changes for", p.fileid);
            return;
          } else {
            console.log("Saving EXIF info for", p.fileid, raw);
          }

          await axios.patch<any>(API.IMAGE_SETEXIF(fileid), { raw });

          // Clear imageInfo in photo
          p.imageInfo = null;

          // Emit event to update photo
          emit("files:file:updated", { fileid });
        } catch (e) {
          console.error("Failed to save EXIF info for", p.fileid, e);
          if (e.response?.data?.message) {
            showError(e.response.data.message);
          } else {
            showError(e);
          }
        } finally {
          done++;
          this.progress = Math.round((done * 100) / this.photos.length);
        }
      });

      for await (const _ of dav.runInParallel(calls, 8)) {
        // nothing to do
      }

      this.processing = false;
      this.close();

      this.emitRefresh(true);
    },
  },
});
</script>

<style scoped lang="scss">
.title-text {
  font-size: 1.05em;
  font-weight: 500;
  margin-top: 25px;

  &:first-of-type {
    margin-top: 10px;
  }
}
</style>
