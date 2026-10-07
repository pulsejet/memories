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
        v-model="albumName"
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
        variant="primary"
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

<script setup lang="ts">
import { computed, ref, useTemplateRef, nextTick, defineAsyncComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';
import AlbumCollaborators from './AlbumCollaborators.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

defineOptions({
  name: 'AlbumShareModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const collaborators = useTemplateRef<InstanceType<typeof AlbumCollaborators>>('collaborators');

const album = ref<any>(null);
const albumName = ref(String());
const loadingAddCollaborators = ref(false);

const showEditFields = computed(() => album.value?.basename?.startsWith('.link-'));

console.assert(!_m.modals.albumShare, 'AlbumShareModal created twice');
_m.modals.albumShare = open;

async function open(user: string, name: string, link?: boolean) {
  show.value = true;

  // Load album info
  try {
    loadingAddCollaborators.value = true;
    albumName.value = name;
    album.value = await dav.getAlbum(user, name);
  } catch {
    showError(t('memories', 'Failed to load album info: {name}', { name }));
  } finally {
    loadingAddCollaborators.value = false;
  }

  // Check if we immediately want to share a link
  if (link) {
    await nextTick(); // load collaborators component
    collaborators.value?.createPublicLinkForAlbum();
  }
}

function cleanup() {
  show.value = false;
  album.value = null;
  albumName.value = String();
}

async function save(collaboratorsIn: any[]) {
  try {
    loadingAddCollaborators.value = true;

    // Update album collaborators
    await dav.updateAlbum(album.value, {
      albumName: album.value.basename,
      properties: { collaborators: collaboratorsIn },
    });

    // Update album name if changed
    if (album.value.basename !== albumName.value) {
      await dav.renameAlbum(album.value, album.value.basename, albumName.value);

      // Change route to new album name if we're on album page
      if (routeIs.Albums) {
        // Do not await but proceed to close modal instantly
        router.replace({
          name: route.name!,
          params: {
            user: route.params.user?.toString(),
            name: albumName.value,
          },
        });
      }
    }

    // Refresh timeline for metadata changes
    utils.bus.emit('memories:timeline:soft-refresh', null);

    // Close modal
    await close();
  } catch (error) {
    console.error(error);
  } finally {
    loadingAddCollaborators.value = false;
  }
}
</script>

<style lang="scss" scoped>
.album-share.loading-icon {
  height: 350px;
}

span.field-title {
  color: var(--color-text-maxcontrast);
}
</style>
