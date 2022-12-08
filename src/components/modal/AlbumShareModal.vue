<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t("memories", "Share Album") }}
    </template>

    <AlbumCollaborators
      v-if="album"
      :album-name="album.basename"
      :collaborators="album.collaborators"
      :public-link="album.publicLink"
    >
      <template slot-scope="{ collaborators }">
        <NcButton
          :aria-label="t('photos', 'Save collaborators for this album.')"
          type="primary"
          :disabled="loadingAddCollaborators"
          @click="handleSetCollaborators(collaborators)"
        >
          <template #icon>
            <NcLoadingIcon v-if="loadingAddCollaborators" />
          </template>
          {{ t("photos", "Save") }}
        </NcButton>
      </template>
    </AlbumCollaborators>
  </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";

import NcButton from "@nextcloud/vue/dist/Components/NcButton";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";

import * as dav from "../../services/DavRequests";

import Modal from "./Modal.vue";
import AlbumCollaborators from "./AlbumCollaborators.vue";

@Component({
  components: {
    NcButton,
    NcLoadingIcon,
    Modal,
    AlbumCollaborators,
  },
})
export default class AlbumShareModal extends Mixins(GlobalMixin) {
  private album: any = null;
  private show = false;
  private loadingAddCollaborators = false;

  @Emit("close")
  public close() {
    this.show = false;
    this.album = null;
  }

  public async open() {
    this.show = true;
    this.loadingAddCollaborators = true;
    const user = this.$route.params.user || "";
    const name = this.$route.params.name || "";
    this.album = await dav.getAlbum(user, name);
    this.loadingAddCollaborators = false;
  }

  async handleSetCollaborators(collaborators: any[]) {
    try {
      this.loadingAddCollaborators = true;
      await dav.updateAlbum(this.album, {
        albumName: this.album.basename,
        properties: { collaborators },
      });
      this.close();
    } catch (error) {
      console.error(error);
    } finally {
      this.loadingAddCollaborators = false;
    }
  }
}
</script>