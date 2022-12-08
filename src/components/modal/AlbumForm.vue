<template>
  <form
    v-if="!showCollaboratorView"
    class="album-form"
    @submit.prevent="submit"
  >
    <div class="form-inputs">
      <NcTextField
        ref="nameInput"
        :value.sync="albumName"
        type="text"
        name="name"
        :required="true"
        autofocus="true"
        :placeholder="t('photos', 'Name of the album')"
      />
      <label>
        <NcTextField
          :value.sync="albumLocation"
          name="location"
          type="text"
          :placeholder="t('photos', 'Location of the album')"
        />
      </label>
    </div>
    <div class="form-buttons">
      <span class="left-buttons">
        <NcButton
          v-if="displayBackButton"
          :aria-label="t('photos', 'Go back to the previous view.')"
          type="tertiary"
          @click="back"
        >
          {{ t("photos", "Back") }}
        </NcButton>
      </span>
      <span class="right-buttons">
        <NcButton
          v-if="sharingEnabled && !editMode"
          :aria-label="t('photos', 'Go to the add collaborators view.')"
          type="secondary"
          :disabled="albumName.trim() === '' || loading"
          @click="showCollaboratorView = true"
        >
          <template #icon>
            <AccountMultiplePlus />
          </template>
          {{ t("photos", "Add collaborators") }}
        </NcButton>
        <NcButton
          :aria-label="saveText"
          type="primary"
          :disabled="albumName === '' || loading"
          @click="submit()"
        >
          <template #icon>
            <NcLoadingIcon v-if="loading" />
            <Send v-else />
          </template>
          {{ saveText }}
        </NcButton>
      </span>
    </div>
  </form>
  <AlbumCollaborators
    v-else
    :album-name="albumName"
    :allow-public-link="false"
    :collaborators="[]"
  >
    <template slot-scope="{ collaborators }">
      <span class="left-buttons">
        <NcButton
          :aria-label="t('photos', 'Back to the new album form.')"
          type="tertiary"
          @click="showCollaboratorView = false"
        >
          {{ t("photos", "Back") }}
        </NcButton>
      </span>
      <span class="right-buttons">
        <NcButton
          :aria-label="saveText"
          type="primary"
          :disabled="albumName.trim() === '' || loading"
          @click="submit(collaborators)"
        >
          <template #icon>
            <NcLoadingIcon v-if="loading" />
            <Send v-else />
          </template>
          {{ saveText }}
        </NcButton>
      </span>
    </template>
  </AlbumCollaborators>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";

import { getCurrentUser } from "@nextcloud/auth";
import NcButton from "@nextcloud/vue/dist/Components/NcButton";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";
const NcTextField = () => import("@nextcloud/vue/dist/Components/NcTextField");
import moment from "moment";
import * as dav from "../../services/DavRequests";

import AlbumCollaborators from "./AlbumCollaborators.vue";

import Send from "vue-material-design-icons/Send.vue";
import AccountMultiplePlus from "vue-material-design-icons/AccountMultiplePlus.vue";

@Component({
  components: {
    NcButton,
    NcLoadingIcon,
    NcTextField,
    AlbumCollaborators,

    Send,
    AccountMultiplePlus,
  },
})
export default class AlbumForm extends Mixins(GlobalMixin) {
  @Prop() private album: any;
  @Prop() private displayBackButton: boolean;

  private showCollaboratorView = false;
  private albumName = "";
  private albumLocation = "";
  private loading = false;

  /**
   * @return Whether sharing is enabled.
   */
  get editMode(): boolean {
    return Boolean(this.album);
  }

  get saveText(): string {
    return this.editMode
      ? this.t("photos", "Save")
      : this.t("photos", "Create album");
  }

  /**
   * @return Whether sharing is enabled.
   */
  get sharingEnabled(): boolean {
    return window.OC.Share !== undefined;
  }

  mounted() {
    if (this.editMode) {
      this.albumName = this.album.basename;
      this.albumLocation = this.album.location;
    }
    this.$nextTick(() => {
      (<any>this.$refs.nameInput).$el.getElementsByTagName("input")[0].focus();
    });
  }

  submit(collaborators = []) {
    if (this.albumName === "" || this.loading) {
      return;
    }
    if (this.editMode) {
      this.handleUpdateAlbum();
    } else {
      this.handleCreateAlbum(collaborators);
    }
  }

  async handleCreateAlbum(collaborators = []) {
    try {
      this.loading = true;
      let album = {
        basename: this.albumName,
        filename: `/photos/${getCurrentUser()?.uid}/albums/${this.albumName}`,
        nbItems: 0,
        location: this.albumLocation,
        lastPhoto: -1,
        date: moment().format("MMMM YYYY"),
        collaborators,
      };
      await dav.createAlbum(album.basename);

      if (this.albumLocation !== "" || collaborators.length !== 0) {
        album = await dav.updateAlbum(album, {
          albumName: this.albumName,
          properties: {
            location: this.albumLocation,
            collaborators,
          },
        });
      }

      this.$emit("done", { album });
    } finally {
      this.loading = false;
    }
  }

  async handleUpdateAlbum() {
    try {
      this.loading = true;
      let album = { ...this.album };
      if (this.album.basename !== this.albumName) {
        album = await dav.renameAlbum(this.album, {
          currentAlbumName: this.album.basename,
          newAlbumName: this.albumName,
        });
      }
      if (this.album.location !== this.albumLocation) {
        album.location = await dav.updateAlbum(this.album, {
          albumName: this.albumName,
          properties: { location: this.albumLocation },
        });
      }
      this.$emit("done", { album });
    } finally {
      this.loading = false;
    }
  }

  @Emit("back")
  back() {}
}
</script>
<style lang="scss" scoped>
.album-form {
  display: flex;
  flex-direction: column;
  height: 350px;
  padding: 16px;
  .form-title {
    font-weight: bold;
  }
  .form-subtitle {
    color: var(--color-text-lighter);
  }
  .form-inputs {
    flex-grow: 1;
    justify-items: flex-end;
    input {
      width: 100%;
    }
    label {
      display: flex;
      margin-top: 16px;
      :deep svg {
        margin-right: 12px;
      }
    }
  }
  .form-buttons {
    display: flex;
    justify-content: space-between;
    .left-buttons,
    .right-buttons {
      display: flex;
    }
    .right-buttons {
      justify-content: flex-end;
    }
    button {
      margin-right: 16px;
    }
  }
}
.left-buttons {
  flex-grow: 1;
}
</style>