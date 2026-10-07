<template>
  <div v-if="!showAlbumCreationForm" ref="root" class="album-picker">
    <XLoadingIcon v-if="loadingAlbums" class="loading-icon centered" />

    <div class="search">
      <NcTextField
        :autofocus="false"
        v-model="search"
        :label="t('memories', 'Search')"
        :placeholder="t('memories', 'Search')"
      >
        <MagnifyIcon :size="16" />
      </NcTextField>
    </div>

    <div class="albums-container">
      <AlbumsList :albums="filteredList" :link="false" @click="toggleAlbumSelection">
        <template #extra="{ album }">
          <div
            :class="{
              'album-selected': selection.has(album),
              'check-circle-icon': true,
              'check-circle-icon--active': selection.has(album),
            }"
            @click="toggleAlbumSelection(album)"
          >
            <CheckIcon :size="20" />
          </div>
        </template>
      </AlbumsList>
    </div>

    <div class="actions">
      <NcButton
        :aria-label="t('memories', 'Create new album.')"
        :disabled="disabled"
        class="new-album-button"
        variant="tertiary"
        @click="showAlbumCreationForm = true"
      >
        <template #icon>
          <PlusIcon />
        </template>
        {{ t('memories', 'Create new album') }}
      </NcButton>

      <div class="submit-btn-wrapper">
        <NcButton
          class="new-album-button"
          variant="primary"
          :aria-label="t('memories', 'Save changes')"
          :disabled="disabled"
          @click="submit"
        >
          {{ t('memories', 'Save changes') }}
        </NcButton>
        <span class="remove-notice" v-if="deselection.size > 0">
          {{
            n('memories', 'Removed from {n} album', 'Removed from {n} albums', deselection.size, {
              n: deselection.size,
            })
          }}
        </span>
      </div>
    </div>
  </div>

  <AlbumForm
    v-else
    :display-back-button="true"
    :title="t('memories', 'New album')"
    @back="showAlbumCreationForm = false"
    @done="albumCreatedHandler"
  />
</template>

<script setup lang="ts">
import {
  computed,
  ref,
  onMounted,
  nextTick,
  markRaw,
  useTemplateRef,
  defineAsyncComponent,
} from 'vue';

import Fuse from 'fuse.js';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import AlbumForm from './AlbumForm.vue';
import AlbumsList from './AlbumsList.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import * as dav from '@services/dav';
import { n, t } from '@services/l10n';

import type { IAlbum, IPhoto } from '@typings';

import PlusIcon from 'vue-material-design-icons/Plus.vue';
import CheckIcon from 'vue-material-design-icons/Check.vue';
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

defineOptions({
  name: 'AlbumPicker',
});

const props = defineProps<{
  /** List of pictures that are selected */
  photos: IPhoto[];
  /** Disable controls */
  disabled?: boolean;
  /** Initial album selection */
  initialSelection?: IAlbum[];
}>();

const emit = defineEmits<{
  (e: 'select', selection: IAlbum[], deselection: IAlbum[]): void;
}>();

const root = useTemplateRef<HTMLDivElement>('root');

const showAlbumCreationForm = ref(false);
const loadingAlbums = ref(true);
/** List of all albums */
const albums = ref<IAlbum[]>([]);
/** Search provider for list to show */
const fuse = ref<Fuse<IAlbum> | null>(null);
/** Initial selection */
const initSelection = ref(new Set<IAlbum>());
/** Selected albums */
const selection = ref(new Set<IAlbum>());
/** Deselected albums that were initially selected */
const deselection = ref(new Set<IAlbum>());
/** Search term */
const search = ref(String());

const filteredList = computed(() => {
  if (!albums.value || !search.value || !fuse.value) return albums.value ?? [];
  return fuse.value.search(search.value).map((r) => r.item);
});

onMounted(async () => {
  void loadAlbums();
  await nextTick();
  // prevent autofocus on search bar for mobile
  (root.value?.closest('.modal-mask') as HTMLElement | null)?.focus?.();
});

async function albumCreatedHandler({ album }: { album: { basename: string } }) {
  showAlbumCreationForm.value = false;
  await loadAlbums(true);

  // select the newly created album
  const newAlbum = albums.value.find((a) => a.name === album.basename);
  if (newAlbum) {
    selection.value.add(newAlbum);
  }
}

async function loadAlbums(preserveSelection: boolean = false) {
  try {
    loadingAlbums.value = true;

    // FIXME: preserve deselection too; but then this is only
    // applicable for single photo selection ... at least for now
    const prevSel = new Set(Array.from(selection.value).map((a) => a.album_id));

    // get all albums
    albums.value = await dav.getAlbums();

    // create search provider
    fuse.value = markRaw(new Fuse(albums.value, { keys: ['name'] }));

    // get initial selection
    let initSelIds: number[] = [];
    const singleFileId = props.photos.length === 1 ? props.photos[0].fileid : 0;

    if (props.initialSelection) {
      // check if selection was passed as a prop
      initSelIds = props.initialSelection.map((a) => a.album_id);
    } else if (singleFileId) {
      // if only one photo is selected, get the albums of that photo
      const pAlbums = await dav.getAlbums(singleFileId);
      initSelIds = pAlbums.map((a) => a.album_id);
    }

    // initialize all sets
    initSelection.value = new Set(albums.value.filter((a) => initSelIds.includes(a.album_id)));
    selection.value = new Set(initSelection.value);
    deselection.value = new Set();

    // restore selection
    if (preserveSelection) {
      albums.value.filter((a) => prevSel.has(a.album_id)).forEach((a) => selection.value.add(a));
    }
  } catch (e) {
    console.error(e);
  } finally {
    loadingAlbums.value = false;
  }
}

function toggleAlbumSelection(album: IAlbum) {
  if (props.disabled) return;

  if (selection.value.has(album)) {
    selection.value.delete(album);

    // deselection only if originally selected
    if (initSelection.value.has(album)) {
      deselection.value.add(album);
    }
  } else {
    selection.value.add(album);
    deselection.value.delete(album);
  }
}

function submit() {
  emit('select', Array.from(selection.value), Array.from(deselection.value));
}
</script>

<style lang="scss" scoped>
.album-picker {
  h2 {
    display: flex;
    align-items: center;
    height: 60px;

    .loading-icon {
      margin-left: 32px;
    }
  }

  .search {
    margin-bottom: 8px;
  }

  .albums-container {
    height: 350px;
    overflow-y: scroll;

    .check-circle-icon {
      border-radius: 50%;
      border: 1px solid rgba($color: black, $alpha: 0.1);
      background-color: transparent;
      height: 34px;
      width: 34px;
      display: flex;
      align-items: center;
      justify-content: center;

      &--active {
        border: 1px solid var(--color-primary);
        background-color: var(--color-primary);
        color: var(--color-primary-text);
      }
    }
  }

  .new-album-button {
    margin-top: 32px;
  }

  .actions {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
  }

  .submit-btn-wrapper {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
  }

  .remove-notice {
    font-size: small;
  }
}
</style>
