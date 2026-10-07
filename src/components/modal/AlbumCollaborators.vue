<template>
  <div class="manage-collaborators">
    <div class="manage-collaborators__subtitle">
      {{ t('memories', 'Add people or groups who can edit your album') }}
    </div>

    <form class="manage-collaborators__form" @submit.prevent>
      <NcPopover :shown="showPopover" :auto-size="true" :distance="0" :focus-trap="false">
        <template #trigger="{ attrs }">
          <label class="manage-collaborators__form__input" v-bind="attrs">
            <NcTextField
              v-model="searchText"
              autocomplete="off"
              type="search"
              name="search"
              aria-autocomplete="list"
              :label="t('memories', 'Search for collaborators')"
              :aria-label="t('memories', 'Search for collaborators')"
              :aria-controls="`manage-collaborators__form__selection-${randomId} manage-collaborators__form__list-${randomId}`"
              :placeholder="t('memories', 'Search people or groups')"
              @input="searchCollaborators"
            >
              <Magnify :size="16" />
            </NcTextField>
            <XLoadingIcon v-if="loadingCollaborators" />
          </label>
        </template>

        <ul
          v-if="searchResults.length !== 0"
          :id="`manage-collaborators__form__list-${randomId}`"
          class="manage-collaborators__form__list"
        >
          <li v-for="collaboratorKey of searchResults" :key="collaboratorKey">
            <NcListItemIcon
              :id="availableCollaborators[collaboratorKey].id"
              class="manage-collaborators__form__list__result"
              :name="availableCollaborators[collaboratorKey].label"
              :search="searchText"
              :user="availableCollaborators[collaboratorKey].id"
              :display-name="availableCollaborators[collaboratorKey].label"
              :aria-label="
                t('memories', 'Add {collaboratorLabel} to the collaborators list', {
                  collaboratorLabel: availableCollaborators[collaboratorKey].label,
                })
              "
              @click="selectEntity(collaboratorKey)"
            />
          </li>
        </ul>
        <NcEmptyContent
          v-else
          key="emptycontent"
          class="manage-collaborators__form__list--empty"
          :name="t('memories', 'No collaborators available')"
        >
          <template #icon>
            <AccountGroup />
          </template>
        </NcEmptyContent>
      </NcPopover>
    </form>

    <ul class="manage-collaborators__selection">
      <li
        v-for="collaboratorKey of listableSelectedCollaboratorsKeys"
        :key="collaboratorKey"
        class="manage-collaborators__selection__item"
      >
        <NcListItemIcon
          :id="availableCollaborators[collaboratorKey].id"
          :display-name="availableCollaborators[collaboratorKey].label"
          :name="availableCollaborators[collaboratorKey].label"
          :user="availableCollaborators[collaboratorKey].id"
        >
          <NcButton
            variant="tertiary"
            :aria-label="
              t('memories', 'Remove {collaboratorLabel} from the collaborators list', {
                collaboratorLabel: availableCollaborators[collaboratorKey].label,
              })
            "
            @click="unselectEntity(collaboratorKey)"
          >
            <template #icon>
              <Close :size="20" />
            </template>
          </NcButton>
        </NcListItemIcon>
      </li>
    </ul>

    <div class="actions">
      <div v-if="allowPublicLink" class="actions__public-link">
        <template v-if="isPublicLinkSelected">
          <NcButton
            class="manage-collaborators__public-link-button"
            :aria-label="t('memories', 'Copy the public link')"
            :disabled="publicLink.id === '' || loadingAlbum"
            @click="copyPublicLink"
          >
            <template v-if="publicLinkCopied">
              {{ t('memories', 'Public link copied!') }}
            </template>
            <template v-else>
              {{ t('memories', 'Copy public link') }}
            </template>
            <template #icon>
              <Check v-if="publicLinkCopied" />
              <ContentCopy v-else />
            </template>
          </NcButton>
          <NcButton
            variant="tertiary"
            :aria-label="t('memories', 'Delete the public link')"
            :disabled="publicLink.id === '' || loadingAlbum"
            @click="deletePublicLink"
          >
            <template #icon>
              <XLoadingIcon v-if="publicLink.id === ''" />
              <Close v-else />
            </template>
          </NcButton>
        </template>
        <NcButton
          v-else
          class="manage-collaborators__public-link-button"
          :disabled="loadingAlbum"
          @click="createPublicLinkForAlbum"
        >
          <template #icon>
            <Earth />
          </template>
          {{ t('memories', 'Share via public link') }}
        </NcButton>
      </div>

      <div class="actions__slot">
        <slot :collaborators="selectedCollaborators" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, watch, defineAsyncComponent } from 'vue';

import Magnify from 'vue-material-design-icons/Magnify.vue';
import Close from 'vue-material-design-icons/Close.vue';
import Check from 'vue-material-design-icons/Check.vue';
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue';
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue';
import Earth from 'vue-material-design-icons/Earth.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { generateOcsUrl, generateUrl } from '@nextcloud/router';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
const NcPopover = defineAsyncComponent(() => import('@nextcloud/vue/components/NcPopover'));
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcListItemIcon = defineAsyncComponent(() => import('@nextcloud/vue/components/NcListItemIcon'));

import { t } from '@services/l10n';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';
import * as nativex from '@native';

import { ShareType } from '@nextcloud/sharing';

type Collaborator = {
  id: string;
  label: string;
  type: ShareType;
};

defineOptions({
  name: 'AlbumCollaborators',
});

const props = defineProps<{
  albumName: string;
  collaborators: Collaborator[];
  allowPublicLink?: boolean;
}>();

defineSlots<{
  default(props: { collaborators: Collaborator[] }): any;
}>();

const searchText = ref('');
const showPopover = ref(false);
const availableCollaborators = ref({} as { [key: string]: Collaborator });
const selectedCollaboratorsKeys = ref<string[]>([]);
const currentSearchResults = ref<Collaborator[]>([]);
const loadingAlbum = ref(false);
const errorFetchingAlbum = ref<number | null>(null);
const loadingCollaborators = ref(false);
const errorFetchingCollaborators = ref(null);
const randomId = Math.random().toString().slice(2, 10);
const publicLinkCopied = ref(false);
const config = {
  minSearchStringLength: parseInt(window.OC.config['sharing.minSearchStringLength'], 10) || 0,
};

const searchResults = computed(() =>
  currentSearchResults.value
    .filter(({ id }) => id !== utils.uid)
    .map(({ type, id }) => `${type}:${id}`)
    .filter((collaboratorKey) => !selectedCollaboratorsKeys.value.includes(collaboratorKey)),
);

const listableSelectedCollaboratorsKeys = computed(() =>
  selectedCollaboratorsKeys.value.filter(
    (collaboratorKey) => availableCollaborators.value[collaboratorKey].type !== ShareType.Link,
  ),
);

const selectedCollaborators = computed(() =>
  selectedCollaboratorsKeys.value.map((collaboratorKey) => availableCollaborators.value[collaboratorKey]),
);

const isPublicLinkSelected = computed(() => selectedCollaboratorsKeys.value.includes(`${ShareType.Link}`));
const publicLink = computed(() => availableCollaborators.value[ShareType.Link]);

watch(
  () => props.collaborators,
  (collaborators) => {
    populateCollaborators(collaborators);
  },
);

onMounted(() => {
  searchCollaborators();
  populateCollaborators(props.collaborators);
});

/** Fetch possible collaborators. */
async function searchCollaborators() {
  if (searchText.value.length >= 1) {
    showPopover.value = true;
  }

  try {
    if (searchText.value.length < config.minSearchStringLength) {
      return;
    }

    loadingCollaborators.value = true;
    const response = await axios.get(generateOcsUrl('core/autocomplete/get'), {
      params: {
        search: searchText.value,
        itemType: 'share-recipients',
        shareTypes: [ShareType.User, ShareType.Group],
      },
    });

    currentSearchResults.value = response.data.ocs.data.map((collaborator: any) => {
      switch (collaborator.source) {
        case 'users':
          return {
            id: collaborator.id,
            label: collaborator.label,
            type: ShareType.User,
          };
        case 'groups':
          return {
            id: collaborator.id,
            label: collaborator.label,
            type: ShareType.Group,
          };
        default:
          throw new Error(`Invalid collaborator source ${collaborator.source}`);
      }
    });

    availableCollaborators.value = {
      ...availableCollaborators.value,
      ...currentSearchResults.value.reduce(indexCollaborators, {}),
    };
  } catch (error: any) {
    errorFetchingCollaborators.value = error;
    showError(t('memories', 'Failed to fetch collaborators list.'));
  } finally {
    loadingCollaborators.value = false;
  }
}

/** Populate selectedCollaboratorsKeys and availableCollaborators. */
function populateCollaborators(collaborators: Collaborator[]) {
  const initialCollaborators = collaborators.reduce(indexCollaborators, {});
  selectedCollaboratorsKeys.value = Object.keys(initialCollaborators);
  availableCollaborators.value = {
    3: {
      id: '',
      label: t('memories', 'Public link'),
      type: ShareType.Link,
    },
    ...availableCollaborators.value,
    ...initialCollaborators,
  };
}

function indexCollaborators(collaborators: { [s: string]: Collaborator }, collaborator: Collaborator) {
  return {
    ...collaborators,
    [`${collaborator.type}${collaborator.type === ShareType.Link ? '' : ':'}${
      collaborator.type === ShareType.Link ? '' : collaborator.id
    }`]: collaborator,
  };
}

async function createPublicLinkForAlbum() {
  if (loadingAlbum.value) return;

  // Check if link already exists
  if (isPublicLinkSelected.value) {
    return await copyPublicLink();
  }

  // Create new link
  selectEntity(`${ShareType.Link}`);
  if (!(await updateAlbumCollaborators())) {
    unselectEntity(`${ShareType.Link}`);
    return;
  }
  try {
    loadingAlbum.value = true;
    errorFetchingAlbum.value = null;

    if (!utils.uid) return;
    const album = await dav.getAlbum(utils.uid, props.albumName);
    populateCollaborators(album.collaborators);
    await copyPublicLink();
  } catch (error: any) {
    if (error.response?.status === 404) {
      errorFetchingAlbum.value = 404;
    } else {
      errorFetchingAlbum.value = error;
    }

    showError(t('memories', 'Failed to fetch album.'));
  } finally {
    loadingAlbum.value = false;
  }
}

async function deletePublicLink() {
  if (loadingAlbum.value) return;
  const collaborators = selectedCollaborators.value.filter((c) => c.type !== ShareType.Link);
  if (!(await updateAlbumCollaborators(collaborators))) return;

  unselectEntity(`${ShareType.Link}`);
  availableCollaborators.value[3] = {
    id: '',
    label: t('memories', 'Public link'),
    type: ShareType.Link,
  };
  publicLinkCopied.value = false;
}

async function updateAlbumCollaborators(collaborators?: Collaborator[]): Promise<boolean> {
  collaborators ??= selectedCollaborators.value;
  try {
    if (!utils.uid) return false;
    loadingAlbum.value = true;
    const album = await dav.getAlbum(utils.uid, props.albumName);
    await dav.updateAlbum(album, {
      albumName: props.albumName,
      properties: {
        collaborators,
      },
    });
    return true;
  } catch (error) {
    showError(t('memories', 'Failed to update album.'));
    return false;
  } finally {
    loadingAlbum.value = false;
  }
}

async function copyPublicLink() {
  const url = generateUrl(`apps/memories/a/${publicLink.value.id}`);
  const link = `${location.origin}${url}`;
  if (nativex.has()) {
    return await nativex.shareUrl(link);
  }

  await navigator.clipboard.writeText(link);
  publicLinkCopied.value = true;
  await new Promise((resolve) => setTimeout(resolve, 2000));
  publicLinkCopied.value = false;
}

function selectEntity(collaboratorKey: string) {
  if (selectedCollaboratorsKeys.value.includes(collaboratorKey)) return;
  selectedCollaboratorsKeys.value.push(collaboratorKey);
  showPopover.value = false;
}

function unselectEntity(collaboratorKey: string) {
  const index = selectedCollaboratorsKeys.value.indexOf(collaboratorKey);

  if (index === -1) {
    return;
  }

  selectedCollaboratorsKeys.value.splice(index, 1);
}

defineExpose({ createPublicLinkForAlbum });
</script>
<style lang="scss" scoped>
.manage-collaborators {
  display: flex;
  flex-direction: column;
  height: 500px;

  &__title {
    font-weight: bold;
  }

  &__subtitle {
    color: var(--color-text-maxcontrast);
  }

  &__public-link-button {
    margin: 4px 0;
  }

  &__form {
    margin-top: 4px 0;
    display: flex;
    flex-direction: column;

    &__input {
      position: relative;
      display: block;

      input {
        width: 100%;
        padding-left: 34px;
      }

      .loading-icon {
        position: absolute;
        top: calc(36px / 2 - 20px / 2);
        right: 8px;
      }
    }

    &__list {
      padding: 8px;
      height: 350px;
      overflow: scroll;

      &__result {
        padding: 8px;
        border-radius: 100px;
        box-sizing: border-box;

        &,
        & * {
          cursor: pointer !important;
        }

        &:hover {
          background: var(--color-background-dark);
        }
      }

      &--empty {
        margin: 100px 0;
      }
    }
  }

  &__selection {
    display: flex;
    flex-direction: column;
    margin-top: 8px;
    flex-grow: 1;

    &__item {
      border-radius: var(--border-radius-pill);
      padding: 0 8px;

      &:hover {
        background: var(--color-background-dark);
      }
    }
  }

  .actions {
    display: flex;
    margin-top: 8px;

    &__public-link {
      display: flex;
      align-items: center;

      button {
        margin-left: 8px;
      }
    }

    &__slot {
      flex-grow: 1;
      display: flex;
      justify-content: flex-end;
      align-items: center;
    }
  }
}
</style>
