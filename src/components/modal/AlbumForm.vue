<template>
  <form v-if="!showCollaboratorView" class="album-form" @submit.prevent>
    <div class="form-inputs">
      <NcTextField
        ref="nameInput"
        type="text"
        name="name"
        autofocus="true"
        :value.sync="albumName"
        :required="true"
        :label="t('memories', 'Album Name')"
        :label-visible="true"
        :placeholder="t('memories', 'Album Name')"
      />
      <NcTextField
        name="location"
        type="text"
        :value.sync="albumLocation"
        :label="t('memories', 'Location')"
        :label-visible="true"
        :placeholder="t('memories', 'Location of the album')"
      />
    </div>
    <div class="form-buttons">
      <span class="left-buttons">
        <NcButton
          v-if="displayBackButton"
          :aria-label="t('memories', 'Go back to the previous view.')"
          type="tertiary"
          @click="back"
        >
          {{ t('memories', 'Back') }}
        </NcButton>
      </span>
      <span class="right-buttons">
        <NcButton
          v-if="sharingEnabled && !editMode"
          :aria-label="t('memories', 'Go to the add collaborators view.')"
          type="secondary"
          :disabled="albumName.trim() === '' || loading"
          @click="showCollaboratorView = true"
        >
          <template #icon>
            <AccountMultiplePlus />
          </template>
          {{ t('memories', 'Add collaborators') }}
        </NcButton>
        <NcButton :aria-label="saveText" type="primary" :disabled="albumName === '' || loading" @click="submit()">
          <template #icon>
            <XLoadingIcon v-if="loading" />
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
    v-slot="{ collaborators }"
  >
    <span class="left-buttons">
      <NcButton
        :aria-label="t('memories', 'Back to the new album form.')"
        type="tertiary"
        @click="showCollaboratorView = false"
      >
        {{ t('memories', 'Back') }}
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
          <XLoadingIcon v-if="loading" />
          <Send v-else />
        </template>
        {{ saveText }}
      </NcButton>
    </span>
  </AlbumCollaborators>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import { showError } from '@nextcloud/dialogs';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import AlbumCollaborators from './AlbumCollaborators.vue';

import { DateTime } from 'luxon';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

import Send from 'vue-material-design-icons/Send.vue';
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue';

export default defineComponent({
  name: 'AlbumForm',
  components: {
    NcButton,
    NcTextField,
    AlbumCollaborators,

    Send,
    AccountMultiplePlus,
  },

  props: {
    album: {
      type: Object as PropType<any>,
      default: null,
    },
    displayBackButton: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    done: (album: any) => true,
    back: () => true,
  },

  data: () => ({
    collaborators: [],
    showCollaboratorView: false,
    albumName: '',
    albumLocation: '',
    loading: false,
  }),

  computed: {
    refs() {
      return this.$refs as {
        nameInput?: VueHTMLComponent;
      };
    },

    /**
     * @return Whether sharing is enabled.
     */
    editMode(): boolean {
      return Boolean(this.album);
    },

    saveText(): string {
      return this.editMode ? this.t('memories', 'Save') : this.t('memories', 'Create album');
    },

    /**
     * @return Whether sharing is enabled.
     */
    sharingEnabled(): boolean {
      return true; // todo
    },
  },

  mounted() {
    if (this.editMode) {
      this.albumName = this.album.basename;
      this.albumLocation = this.album.location;
    }
    this.$nextTick(() => {
      this.refs.nameInput?.$el.getElementsByTagName('input')[0].focus();
    });
  },

  methods: {
    submit(collaborators: any[] = []) {
      if (this.albumName === '' || this.loading) {
        return;
      }

      // Validate the album name, it shouldn't contain any slash
      if (this.albumName.includes('/')) {
        showError(this.t('memories', 'Invalid album name; should not contain any slashes.'));
        return;
      }

      if (this.editMode) {
        this.handleUpdateAlbum();
      } else {
        this.handleCreateAlbum(collaborators);
      }
    },

    async handleCreateAlbum(collaborators: any[] = []) {
      try {
        this.loading = true;
        let album = {
          basename: this.albumName,
          filename: `/photos/${utils.uid}/albums/${this.albumName}`,
          nbItems: 0,
          location: this.albumLocation,
          lastPhoto: -1,
          date: DateTime.now().toFormat('MMMM YYYY'),
          collaborators,
        };
        await dav.createAlbum(album.basename);

        if (this.albumLocation !== '' || collaborators.length !== 0) {
          album = await dav.updateAlbum(album, {
            albumName: this.albumName,
            properties: {
              location: this.albumLocation,
              collaborators,
            },
          });
        }

        this.$emit('done', { album });
      } finally {
        this.loading = false;
      }
    },

    async handleUpdateAlbum() {
      try {
        this.loading = true;
        let album = { ...this.album };
        if (album.basename !== this.albumName) {
          album = await dav.renameAlbum(album, album.basename, this.albumName);
        }
        if (album.location !== this.albumLocation) {
          album.location = await dav.updateAlbum(album, {
            albumName: album.basename,
            properties: { location: this.albumLocation },
          });
        }
        this.$emit('done', { album });
      } finally {
        this.loading = false;
      }
    },

    back() {
      this.$emit('back');
    },
  },
});
</script>
<style lang="scss" scoped>
.album-form {
  display: flex;
  flex-direction: column;
  height: 230px;
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
    flex-direction: column;
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
