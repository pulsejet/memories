<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Share Album') }}
    </template>

    <template v-if="showEditFields">
      <span class="field-title">
        {{ t('memories', 'Name of the album') }}
      </span>

      <NcTextField
        :value.sync="albumName"
        type="text"
        name="name"
        :required="true"
        autofocus="true"
        :placeholder="t('memories', 'Name of the album')"
      />
    </template>

    <AlbumCollaborators
      v-if="album"
      ref="collaborators"
      :album-name="album.basename"
      :collaborators="album.collaborators"
      :public-link="album.publicLink"
      :allow-public-link="true"
      v-slot="{ collaborators }"
    >
      <NcButton
        :aria-label="t('memories', 'Save collaborators for this album.')"
        type="primary"
        :disabled="loadingAddCollaborators"
        @click="save(collaborators)"
      >
        <template #icon>
          <XLoadingIcon v-if="loadingAddCollaborators" />
        </template>
        {{ t('memories', 'Save') }}
      </NcButton>
    </AlbumCollaborators>

    <XLoadingIcon class="album-share fill-block" v-else />
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumCollaborators from './AlbumCollaborators.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';

export default defineComponent({
  name: 'AlbumShareModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
    AlbumCollaborators,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    album: null as any,
    albumName: String(),
    loadingAddCollaborators: false,
    collaborators: [] as any[],
  }),

  computed: {
    refs() {
      return this.$refs as {
        collaborators?: InstanceType<typeof AlbumCollaborators>;
      };
    },

    showEditFields() {
      return this.album?.basename?.startsWith('.link-');
    },
  },

  created() {
    console.assert(!_m.modals.albumShare, 'AlbumShareModal created twice');
    _m.modals.albumShare = this.open;
  },

  methods: {
    async open(user: string, name: string, link?: boolean) {
      this.show = true;

      // Load album info
      try {
        this.loadingAddCollaborators = true;
        this.albumName = name;
        this.album = await dav.getAlbum(user, name);
      } catch {
        showError(this.t('memories', 'Failed to load album info: {name}', { name }));
      } finally {
        this.loadingAddCollaborators = false;
      }

      // Check if we immediately want to share a link
      if (link) {
        await this.$nextTick(); // load collaborators component
        this.refs.collaborators?.createPublicLinkForAlbum();
      }
    },

    cleanup() {
      this.show = false;
      this.album = null;
      this.albumName = String();
    },

    async save(collaborators: any[]) {
      try {
        this.loadingAddCollaborators = true;

        // Update album collaborators
        await dav.updateAlbum(this.album, {
          albumName: this.album.basename,
          properties: { collaborators },
        });

        // Update album name if changed
        if (this.album.basename !== this.albumName) {
          await dav.renameAlbum(this.album, this.album.basename, this.albumName);

          // Change route to new album name if we're on album page
          if (this.routeIsAlbums) {
            // Do not await but proceed to close modal instantly
            this.$router.replace({
              name: this.$route.name!,
              params: {
                user: this.$route.params.user,
                name: this.albumName,
              },
            });
          }
        }

        // Refresh timeline for metadata changes
        utils.bus.emit('memories:timeline:soft-refresh', null);

        // Close modal
        await this.close();
      } catch (error) {
        console.error(error);
      } finally {
        this.loadingAddCollaborators = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.album-share.loading-icon {
  height: 350px;
}

span.field-title {
  color: var(--color-text-lighter);
}
</style>
