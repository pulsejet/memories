<template>
  <Modal @close="close" size="normal" v-if="show">
    <template #title>
      <template v-if="!album">
        {{ t("memories", "Create new album") }}
      </template>
      <template v-else>
        {{ t("memories", "Edit album details") }}
      </template>
    </template>

    <div class="outer">
      <AlbumForm
        :album="album"
        :display-back-button="false"
        :title="t('photos', 'New album')"
        @done="done"
      />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import { showError } from "@nextcloud/dialogs";
import * as dav from "../../services/DavRequests";

import Modal from "./Modal.vue";
import AlbumForm from "./AlbumForm.vue";

export default defineComponent({
  name: "AlbumCreateModal",
  components: {
    Modal,
    AlbumForm,
  },

  data() {
    return {
      show: false,
      album: null as any,
    };
  },

  methods: {
    /**
     * Open the modal
     * @param edit If true, the modal will be opened in edit mode
     */
    async open(edit: boolean) {
      if (edit) {
        try {
          this.album = await dav.getAlbum(
            <string>this.$route.params.user,
            <string>this.$route.params.name
          );
        } catch (e) {
          console.error(e);
          showError(this.t("photos", "Could not load the selected album"));
          return;
        }
      } else {
        this.album = null;
      }

      this.show = true;
    },

    close() {
      this.show = false;
      this.$emit("close");
    },

    done({ album }: any) {
      if (!this.album || album.basename !== this.album.basename) {
        const user = album.filename.split("/")[2];
        const name = album.basename;
        this.$router.push({ name: "albums", params: { user, name } });
      }
      this.close();
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}

.info-pad {
  margin-top: 6px;
}
</style>