<template>
  <form v-if="!showCollaboratorView" class="album-form" @submit.prevent>
    <div class="form-inputs">
      <NcTextField
        ref="nameInput"
        type="text"
        name="name"
        autofocus="true"
        v-model="albumName"
        :required="true"
        :label="t('memories', 'Album Name')"
        :label-visible="true"
        :placeholder="t('memories', 'Album Name')"
      />
      <NcTextField
        name="location"
        type="text"
        v-model="albumLocation"
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
          variant="tertiary"
          @click="back"
        >
          {{ t('memories', 'Back') }}
        </NcButton>
      </span>
      <span class="right-buttons">
        <NcButton
          v-if="sharingEnabled && !editMode"
          :aria-label="t('memories', 'Go to the add collaborators view.')"
          variant="secondary"
          :disabled="albumName.trim() === '' || loading"
          @click="showCollaboratorView = true"
        >
          <template #icon>
            <AccountMultiplePlus />
          </template>
          {{ t('memories', 'Add collaborators') }}
        </NcButton>
        <NcButton :aria-label="saveText" variant="primary" :disabled="albumName === '' || loading" @click="submit()">
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
        variant="tertiary"
        @click="showCollaboratorView = false"
      >
        {{ t('memories', 'Back') }}
      </NcButton>
    </span>
    <span class="right-buttons">
      <NcButton
        :aria-label="saveText"
        variant="primary"
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

<script setup lang="ts">
import { computed, ref, useTemplateRef, onMounted, nextTick } from 'vue';

import { showError } from '@services/utils/dialog';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import AlbumCollaborators from './AlbumCollaborators.vue';

import { DateTime } from 'luxon';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

import Send from 'vue-material-design-icons/Send.vue';
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

defineOptions({
  name: 'AlbumForm',
});

const props = defineProps<{
  album?: any;
  displayBackButton?: boolean;
}>();

const emit = defineEmits<{
  (e: 'done', data: { album: any }): void;
  (e: 'back'): void;
}>();

const nameInput = useTemplateRef<VueHTMLComponent>('nameInput');

const showCollaboratorView = ref(false);
const albumName = ref('');
const albumLocation = ref('');
const loading = ref(false);

/** Whether sharing is enabled. */
const editMode = computed(() => Boolean(props.album));
const saveText = computed(() => (editMode.value ? t('memories', 'Save') : t('memories', 'Create album')));

/** Whether sharing is enabled. */
const sharingEnabled = computed(() => true); // todo

onMounted(() => {
  if (editMode.value) {
    albumName.value = props.album.basename;
    albumLocation.value = props.album.location;
  }
  nextTick(() => {
    nameInput.value?.$el.getElementsByTagName('input')[0].focus();
  });
});

function submit(collaborators: any[] = []) {
  if (albumName.value === '' || loading.value) {
    return;
  }

  // Validate the album name, it shouldn't contain any slash
  if (albumName.value.includes('/')) {
    showError(t('memories', 'Invalid album name; should not contain any slashes.'));
    return;
  }

  if (editMode.value) {
    handleUpdateAlbum();
  } else {
    handleCreateAlbum(collaborators);
  }
}

async function handleCreateAlbum(collaborators: any[] = []) {
  try {
    loading.value = true;
    let album = {
      basename: albumName.value,
      filename: `/photos/${utils.uid}/albums/${albumName.value}`,
      nbItems: 0,
      location: albumLocation.value,
      lastPhoto: -1,
      date: DateTime.now().toFormat('MMMM YYYY'),
      collaborators,
    };
    await dav.createAlbum(album.basename);

    if (albumLocation.value !== '' || collaborators.length !== 0) {
      album = await dav.updateAlbum(album, {
        albumName: albumName.value,
        properties: {
          location: albumLocation.value,
          collaborators,
        },
      });
    }

    emit('done', { album });
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
}

async function handleUpdateAlbum() {
  try {
    loading.value = true;
    let album = { ...props.album };
    if (album.basename !== albumName.value) {
      album = await dav.renameAlbum(album, album.basename, albumName.value);
    }
    if (album.location !== albumLocation.value) {
      album.location = await dav.updateAlbum(album, {
        albumName: album.basename,
        properties: { location: albumLocation.value },
      });
    }
    emit('done', { album });
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
}

function back() {
  emit('back');
}
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
    color: var(--color-text-maxcontrast);
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
      :deep(svg) {
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
