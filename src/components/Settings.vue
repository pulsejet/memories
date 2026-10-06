<template>
  <div>
    <NcAppSettingsDialog
      id="memories-settings"
      class="memories-modal"
      :open="props.open"
      :show-navigation="true"
      :name="names.header"
      @update:open="onClose"
    >
      <NcAppSettingsSection id="general-settings" :name="names.general">
        <div class="radio-group timeline-paths">
          <div class="title">{{ t('memories', 'Timeline Path') }}</div>
          <div class="chips">
            <NcChip
              v-for="path in timelinePaths"
              :key="path"
              :text="path"
              :aria-label-close="t('memories', 'Remove {path} from timeline', { path })"
              @close="removeTimelinePath(path)"
            />
            <NcChip
              class="add-chip"
              :text="t('memories', 'Add path')"
              :aria-label="t('memories', 'Add a folder to the timeline')"
              variant="tertiary"
              no-close
              role="button"
              tabindex="0"
              @click="addTimelinePath"
              @keydown.enter="addTimelinePath"
              @keydown.space.prevent="addTimelinePath"
            />
          </div>
        </div>

        <NcCheckboxRadioSwitch v-model="config.square_thumbs" @update:model-value="updateSquareThumbs" type="switch">
          {{ t('memories', 'Square grid mode') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.enable_top_memories"
          @update:model-value="updateEnableTopMemories"
          type="switch"
        >
          {{ t('memories', 'Show past photos on top of timeline') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch v-model="config.stack_raw_files" @update:model-value="updateStackRawFiles" type="switch">
          {{ t('memories', 'Stack RAW files with same name') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.dedup_identical"
          @update:model-value="updateDedupIdentical"
          type="switch"
        >
          {{ t('memories', 'De-duplicate identical files') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.show_owner_name_timeline"
          @update:model-value="updateShowOwnerNameTimeline"
          type="switch"
        >
          {{ t('memories', 'Show photo owner name on timeline') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="viewer-settings" :name="names.viewer">
        <NcCheckboxRadioSwitch
          v-model="config.livephoto_autoplay"
          @update:model-value="updateLivephotoAutoplay"
          type="switch"
        >
          {{ t('memories', 'Autoplay Live Photos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch v-model="config.livephoto_loop" @update:model-value="updateLivephotoLoop" type="switch">
          {{ t('memories', 'Loop Live Photos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :model-value="config.video_autoplay === 'true'"
          :disabled="config.video_autoplay === 'disallow'"
          @update:model-value="updateVideoAutoplay"
          type="switch"
        >
          {{ t('memories', 'Autoplay Videos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch v-model="config.video_loop" @update:model-value="updateVideoLoop" type="switch">
          {{ t('memories', 'Loop Videos') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.sidebar_filepath"
          @update:model-value="updateSidebarFilepath"
          type="switch"
        >
          {{ t('memories', 'Show full file path in sidebar') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.metadata_in_slideshow"
          @update:model-value="updateMetadataInSlideshow"
          type="switch"
        >
          {{ t('memories', 'Show metadata in slideshow') }}
        </NcCheckboxRadioSwitch>

        <NcTextField
          :label="t('memories', 'Slideshow Duration (1-60 seconds)')"
          :label-visible="true"
          :model-value="config.slideshow_duration"
          type="number"
          min="1"
          max="60"
          step="1"
          @update:model-value="updateSlideshowDuration"
        />

        <div class="radio-group">
          <div class="title">{{ t('memories', 'High resolution image loading behavior') }}</div>
          <NcCheckboxRadioSwitch
            :model-value="highResCond"
            value="zoom"
            name="vhrc_radio"
            type="radio"
            @update:model-value="updateHighResCond($event)"
            >{{ t('memories', 'Load high resolution image on zoom') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :model-value="highResCond"
            value="always"
            name="vhrc_radio"
            type="radio"
            @update:model-value="updateHighResCond($event)"
            >{{ t('memories', 'Always load high resolution image (not recommended)') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            :model-value="highResCond"
            value="never"
            name="vhrc_radio"
            type="radio"
            @update:model-value="updateHighResCond($event)"
            >{{ t('memories', 'Never load high resolution image') }}
          </NcCheckboxRadioSwitch>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="account-settings" :name="names.account" v-if="isNative">
        <div class="radio-group">
          {{ t('memories', 'Logged in as {user}', { user }) }}
          <NcButton class="setting-button" @click="logout">
            {{ t('memories', 'Sign out') }}
          </NcButton>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="device-settings" :name="t('memories', 'Device Folders')" v-if="isNative">
        <div class="radio-group">
          {{ t('memories', 'Local folders to include in the timeline view') }}
          <NcCheckboxRadioSwitch
            v-for="folder in localFolders"
            :key="folder.id"
            v-model="folder.enabled"
            @update:model-value="updateDeviceFolders"
            type="switch"
          >
            {{ folder.name }}
          </NcCheckboxRadioSwitch>

          <NcButton class="setting-button" @click="runNxSetup()" variant="secondary">
            {{ t('memories', 'Run initial device setup') }}
          </NcButton>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="folders-settings" :name="names.folders">
        <NcTextField
          :label="t('memories', 'Folders Path')"
          :label-visible="true"
          v-model="config.folders_path"
          @click="chooseFoldersPath"
          readonly
        />

        <NcCheckboxRadioSwitch
          v-model="config.show_hidden_folders"
          @update:model-value="updateShowHidden"
          type="switch"
        >
          {{ t('memories', 'Show hidden folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          v-model="config.sort_folder_month"
          @update:model-value="updateSortFolderMonth"
          type="switch"
        >
          {{ t('memories', 'Sort folders oldest-first') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="albums-settings" :name="names.albums">
        <NcCheckboxRadioSwitch
          v-model="config.sort_album_month"
          @update:model-value="updateSortAlbumMonth"
          type="switch"
        >
          {{ t('memories', 'Sort albums oldest-first') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch v-model="config.show_hidden_albums" @update:model-value="updateShowHidden" type="switch">
          {{ t('memories', 'Show hidden albums') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="map-settings" :name="names.map" v-if="tileServers.length > 0">
        <div class="radio-group">
          <NcCheckboxRadioSwitch
            v-for="tile in tileServers"
            :key="tile.name"
            :model-value="config.map_tile_server_url"
            :value="tile.url"
            name="map_style_radio"
            type="radio"
            @update:model-value="updateMapTileServer($event)"
            >{{ tile.name }}
          </NcCheckboxRadioSwitch>
        </div>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="onthisday-settings" :name="names.onthisday">
        <NcTextField
          :label="t('memories', 'Day range (0-7)')"
          :label-visible="true"
          :model-value="config.onthisday_day_range"
          type="number"
          min="0"
          max="7"
          step="1"
          @update:model-value="updateOnThisDayRange"
          :helper-text="t('memories', 'Number of days before and after each anniversary')"
        />

        <NcTextField
          :label="t('memories', 'Photos per year (1-50)')"
          :label-visible="true"
          :model-value="config.onthisday_photos_per_year"
          type="number"
          min="1"
          max="50"
          step="1"
          @update:model-value="updateOnThisDayPhotos"
          :helper-text="t('memories', 'Maximum number of photos to include per year')"
        />
      </NcAppSettingsSection>
    </NcAppSettingsDialog>
  </div>
</template>

<style scoped>
input[type='text'] {
  width: 100%;
}
</style>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useRouter } from 'vue-router';

import { useUserConfig } from '@services/user-config';
import staticConfig from '@services/static-config';
import * as utils from '@services/utils';
import * as nativex from '@native';
import { t } from '@services/l10n';
import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcAppSettingsDialog = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAppSettingsDialog'));
const NcAppSettingsSection = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAppSettingsSection'));
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));
const NcChip = defineAsyncComponent(() => import('@nextcloud/vue/components/NcChip'));

import type { IConfig, IMapTileServer } from '@typings';

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:open', open: boolean): void;
}>();

const router = useRouter();
const { config, updateSetting } = useUserConfig();

const localFolders = ref<nativex.LocalFolderConfig[]>([]);
const names = {
  header: t('memories', 'Settings'),
  general: t('memories', 'General'),
  viewer: t('memories', 'Photo Viewer'),
  onthisday: t('memories', 'On This Day'),
  account: t('memories', 'Account'),
  folders: t('memories', 'Folders'),
  albums: t('memories', 'Albums'),
  map: t('memories', 'Map Tiles'),
};

const timelinePaths = computed((): string[] => {
  return (config.timeline_path || '').split(';').filter((p) => p && !p.startsWith('_'));
});

const isNative = computed((): boolean => {
  return nativex.has();
});

const user = computed((): string => {
  return utils.uid ?? String();
});

const highResCond = computed((): IConfig['high_res_cond_default'] => {
  return config.high_res_cond || config.high_res_cond_default || 'zoom';
});

const tileServers = computed((): IMapTileServer[] => {
  return staticConfig.getSync('map_tile_servers') || [];
});

watch(
  () => props.open,
  (value: boolean) => {
    utils.fragment.if(value, utils.fragment.types.settings);
  },
);

onMounted(() => {
  if (isNative.value) {
    refreshNativeConfig();
  }

  // Fragment navigation
  utils.bus.on('memories:fragment:pop:settings', onClose);
});

onBeforeUnmount(() => {
  utils.bus.off('memories:fragment:pop:settings', onClose);
});

function onClose() {
  emit('update:open', false);
}

// Paths settings
async function addTimelinePath() {
  let folder: string;
  try {
    folder = await utils.chooseNcFolder(t('memories', 'Add a root to your timeline'));
  } catch {
    return;
  }

  if (!folder || timelinePaths.value.includes(folder)) return;
  await saveTimelinePaths([...timelinePaths.value, folder]);
}

async function removeTimelinePath(path: string) {
  const paths = timelinePaths.value.filter((p) => p !== path);
  if (!paths.length) {
    showError(t('memories', 'At least one timeline path is required'));
    return;
  }
  await saveTimelinePaths(paths);
}

async function saveTimelinePaths(paths: string[]) {
  const newPath = paths.join(';');
  if (newPath !== config.timeline_path) {
    config.timeline_path = newPath;
    await updateSetting('timeline_path', 'timelinePath');
  }
}

async function chooseFoldersPath() {
  const newPath = await utils.chooseNcFolder(
    t('memories', 'Choose the root for the folders view'),
    config.folders_path,
  );

  if (newPath !== config.folders_path) {
    config.folders_path = newPath;
    await updateSetting('folders_path', 'foldersPath');
  }
}

// General settings
async function updateSquareThumbs() {
  await updateSetting('square_thumbs');
}

async function updateEnableTopMemories() {
  await updateSetting('enable_top_memories', 'enableTopMemories');
}

async function updateStackRawFiles() {
  await updateSetting('stack_raw_files', 'stackRawFiles');
}

async function updateDedupIdentical() {
  await updateSetting('dedup_identical', 'dedupIdentical');
}

async function updateShowOwnerNameTimeline() {
  await updateSetting('show_owner_name_timeline', 'showOwnerNameTimeline');
}

// Viewer settings
async function updateHighResCond(val: IConfig['high_res_cond']) {
  config.high_res_cond = val;
  await updateSetting('high_res_cond');
}

async function updateLivephotoAutoplay() {
  await updateSetting('livephoto_autoplay', 'livephotoAutoplay');
}

async function updateLivephotoLoop() {
  await updateSetting('livephoto_loop', 'livephotoLoop');
}

async function updateVideoLoop() {
  await updateSetting('video_loop', 'videoLoop');
}

async function updateVideoAutoplay(val: boolean) {
  config.video_autoplay = val ? 'true' : 'false';
  await updateSetting('video_autoplay', 'videoAutoplay');
}

async function updateSidebarFilepath() {
  await updateSetting('sidebar_filepath', 'sidebarFilepath');
}

async function updateMetadataInSlideshow() {
  await updateSetting('metadata_in_slideshow', 'metadataInSlideshow');
}

async function updateSlideshowDuration(val: string | number) {
  const n = typeof val === 'number' ? val : parseFloat(val);
  if (!Number.isFinite(n)) return;
  config.slideshow_duration = Math.min(60, Math.max(1, Math.round(n)));
  await updateSetting('slideshow_duration', 'slideshowDuration');
}

// On This Day settings
async function updateOnThisDayRange(val: string | number) {
  const n = typeof val === 'number' ? val : parseFloat(val);
  if (!Number.isFinite(n)) return;
  config.onthisday_day_range = Math.min(7, Math.max(0, Math.round(n)));
  await updateSetting('onthisday_day_range', 'onthisdayDayRange');
}

async function updateOnThisDayPhotos(val: string | number) {
  const n = typeof val === 'number' ? val : parseFloat(val);
  if (!Number.isFinite(n)) return;
  config.onthisday_photos_per_year = Math.min(50, Math.max(1, Math.round(n)));
  await updateSetting('onthisday_photos_per_year', 'onthisdayPhotosPerYear');
}

// Folders settings
async function updateShowHidden() {
  await updateSetting('show_hidden_folders', 'showHidden');
  await updateSetting('show_hidden_albums', 'showHiddenAlbums');
}

async function updateSortFolderMonth() {
  await updateSetting('sort_folder_month', 'sortFolderMonth');
}

// Albums settings
async function updateSortAlbumMonth() {
  await updateSetting('sort_album_month', 'sortAlbumMonth');
}

// Map settings
async function updateMapTileServer(val: string) {
  config.map_tile_server_url = val;
  await updateSetting('map_tile_server_url', 'mapTileServerUrl');
}

// --------------- Native APIs start -----------------------------
function refreshNativeConfig() {
  localFolders.value = nativex.getLocalFolders();
}

function updateDeviceFolders() {
  nativex.setLocalFolders(localFolders.value);
}

function runNxSetup() {
  router.replace('/nxsetup');
}

async function logout() {
  if (
    await utils.confirmDestructive({
      title: t('memories', 'Sign out'),
      message: t('memories', 'Are you sure you want to log out {user}?', { user: user.value }),
      confirm: t('memories', 'Sign out'),
      confirmClasses: 'error',
      cancel: t('memories', 'Cancel'),
    })
  ) {
    nativex.logout();
  }
}
</script>

<style lang="scss" scoped>
#memories-settings {
  :deep(.app-settings__content) {
    // Fix weirdness when focusing on toggle input on mobile
    position: relative;

    .app-settings-section__content {
      padding: 6px 6px;
      margin-block-start: 0;
      gap: 2px;
    }

    .input-field,
    .radio-group {
      margin-left: 10px;
      margin-right: 12px;
      margin-top: 1em;
    }

    .input-field__helper-text-message {
      font-size: 0.8em;
    }

    input[readonly] {
      cursor: pointer;
      user-select: none;
    }

    @media (max-width: 600px) {
      &,
      & .app-settings-section__content {
        padding: 0;
      }
      .input-field {
        width: calc(100% - 22px);
      }
    }
  }

  .timeline-paths {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;

    .title {
      font-weight: bold;
    }

    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;

      :deep(.nc-chip) {
        padding: 3px;
        height: auto;
      }
      :deep(button.nc-chip__actions) {
        height: var(--chip-size);
      }
      :deep(.add-chip),
      :deep(.add-chip *) {
        cursor: pointer;
      }
    }
  }

  :deep(.setting-button) {
    margin-top: 10px;
  }
}
</style>
