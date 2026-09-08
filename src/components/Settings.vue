<template>
  <div>
    <NcAppSettingsDialog
      id="memories-settings"
      class="memories-modal"
      :open="open"
      :show-navigation="true"
      :name="names.header"
      @update:open="onClose"
    >
      <NcAppSettingsSection id="general-settings" :name="names.general">
        <NcTextField
          :label="t('memories', 'Timeline Path')"
          :label-visible="true"
          v-model="config.timeline_path"
          @click="chooseTimelinePath"
          readonly
        />

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
        {{ t('memories', 'Logged in as {user}', { user }) }}
        <NcButton @click="logout" id="sign-out">
          {{ t('memories', 'Sign out') }}
        </NcButton>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="device-settings" :name="t('memories', 'Device Folders')" v-if="isNative">
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

        <NcButton @click="runNxSetup()" variant="secondary">
          {{ t('memories', 'Run initial device setup') }}
        </NcButton>
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

      <NcAppSettingsSection id="onthisday-settings" :name="names.onthisday">
        <NcTextField
          :label="t('memories', 'Day range (1-7)')"
          :label-visible="true"
          v-model="config.onthisday_day_range"
          type="number"
          min="1"
          max="7"
          step="1"
          @input="updateOnThisDayRange"
        />
        <div class="settings-hint">
          {{ t('memories', 'Number of days before and after each anniversary to include') }}
        </div>

        <NcTextField
          :label="t('memories', 'Photos per year (1-50)')"
          :label-visible="true"
          v-model="config.onthisday_photos_per_year"
          type="number"
          min="1"
          max="50"
          step="1"
          @input="updateOnThisDayPhotos"
        />
        <div class="settings-hint">
          {{ t('memories', 'Maximum number of photos to include per year') }}
        </div>
      </NcAppSettingsSection>
    </NcAppSettingsDialog>

    <MultiPathSelectionModal ref="multiPathModal" :title="pathSelTitle" @close="saveTimelinePath" />
  </div>
</template>

<style scoped>
input[type='text'] {
  width: 100%;
}

div.settings-hint {
  font-size: 0.8rem;
  margin-left: 0.6rem;
  margin-bottom: 0.6rem;
  color: var(--color-text-lighter);
}
</style>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';
import * as nativex from '@native';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcAppSettingsDialog = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAppSettingsDialog'));
const NcAppSettingsSection = defineAsyncComponent(() => import('@nextcloud/vue/components/NcAppSettingsSection'));
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import MultiPathSelectionModal from '@components/modal/MultiPathSelectionModal.vue';

import type { IConfig } from '@typings';

export default defineComponent({
  name: 'Settings',

  components: {
    NcButton,
    NcTextField,
    NcAppSettingsDialog,
    NcAppSettingsSection,
    NcCheckboxRadioSwitch,
    MultiPathSelectionModal,
  },

  mixins: [UserConfig],

  emits: {
    'update:open': (open: boolean) => true,
  },

  data: () => ({
    localFolders: [] as nativex.LocalFolderConfig[],
    names: {
      header: t('memories', 'Memories Settings'),
      general: t('memories', 'General'),
      viewer: t('memories', 'Photo Viewer'),
      onthisday: t('memories', 'On This Day'),
      account: t('memories', 'Account'),
      folders: t('memories', 'Folders'),
      albums: t('memories', 'Albums'),
    },
  }),

  props: {
    open: {
      type: Boolean,
      required: true,
    },
  },

  computed: {
    pathSelTitle(): string {
      return this.t('memories', 'Choose Timeline Paths');
    },

    isNative(): boolean {
      return nativex.has();
    },

    user(): string {
      return utils.uid ?? String();
    },

    highResCond(): IConfig['high_res_cond_default'] {
      return this.config.high_res_cond || this.config.high_res_cond_default || 'zoom';
    },
  },

  watch: {
    open(value: boolean) {
      utils.fragment.if(value, utils.fragment.types.settings);
    },
  },

  mounted() {
    if (this.isNative) {
      this.refreshNativeConfig();
    }

    // Fragment navigation
    utils.bus.on('memories:fragment:pop:settings', this.onClose);
  },

  beforeUnmount() {
    utils.bus.off('memories:fragment:pop:settings', this.onClose);
  },

  methods: {
    refs() {
      return this.$refs as {
        multiPathModal: InstanceType<typeof MultiPathSelectionModal>;
      };
    },

    onClose() {
      this.$emit('update:open', false);
    },

    // Paths settings
    async chooseTimelinePath() {
      this.refs().multiPathModal.open(this.config.timeline_path.split(';'));
    },

    async saveTimelinePath(paths: string[]) {
      if (!paths || !paths.length) return;

      const newPath = paths.join(';');
      if (newPath !== this.config.timeline_path) {
        this.config.timeline_path = newPath;
        await this.updateSetting('timeline_path', 'timelinePath');
      }
    },

    async chooseFoldersPath() {
      const newPath = await utils.chooseNcFolder(
        this.t('memories', 'Choose the root for the folders view'),
        this.config.folders_path,
      );

      if (newPath !== this.config.folders_path) {
        this.config.folders_path = newPath;
        await this.updateSetting('folders_path', 'foldersPath');
      }
    },

    // General settings
    async updateSquareThumbs() {
      await this.updateSetting('square_thumbs');
    },

    async updateEnableTopMemories() {
      await this.updateSetting('enable_top_memories', 'enableTopMemories');
    },

    async updateStackRawFiles() {
      await this.updateSetting('stack_raw_files', 'stackRawFiles');
    },

    async updateDedupIdentical() {
      await this.updateSetting('dedup_identical', 'dedupIdentical');
    },

    async updateShowOwnerNameTimeline() {
      await this.updateSetting('show_owner_name_timeline', 'showOwnerNameTimeline');
    },

    // Viewer settings
    async updateHighResCond(val: IConfig['high_res_cond']) {
      this.config.high_res_cond = val;
      await this.updateSetting('high_res_cond');
    },

    async updateLivephotoAutoplay() {
      await this.updateSetting('livephoto_autoplay', 'livephotoAutoplay');
    },

    async updateLivephotoLoop() {
      await this.updateSetting('livephoto_loop', 'livephotoLoop');
    },

    async updateVideoLoop() {
      await this.updateSetting('video_loop', 'videoLoop');
    },

    async updateSidebarFilepath() {
      await this.updateSetting('sidebar_filepath', 'sidebarFilepath');
    },

    async updateMetadataInSlideshow() {
      await this.updateSetting('metadata_in_slideshow', 'metadataInSlideshow');
    },

    // On This Day settings
    async updateOnThisDayRange() {
      await this.updateSetting('onthisday_day_range', 'onthisdayDayRange');
    },

    async updateOnThisDayPhotos() {
      await this.updateSetting('onthisday_photos_per_year', 'onthisdayPhotosPerYear');
    },

    // Folders settings
    async updateShowHidden() {
      await this.updateSetting('show_hidden_folders', 'showHidden');
      await this.updateSetting('show_hidden_albums', 'showHiddenAlbums');
    },

    async updateSortFolderMonth() {
      await this.updateSetting('sort_folder_month', 'sortFolderMonth');
    },

    // Albums settings
    async updateSortAlbumMonth() {
      await this.updateSetting('sort_album_month', 'sortAlbumMonth');
    },

    // --------------- Native APIs start -----------------------------
    refreshNativeConfig() {
      this.localFolders = nativex.getLocalFolders();
    },

    updateDeviceFolders() {
      nativex.setLocalFolders(this.localFolders);
    },

    runNxSetup() {
      this.$router.replace('/nxsetup');
    },

    async logout() {
      if (
        await utils.confirmDestructive({
          title: this.t('memories', 'Sign out'),
          message: this.t('memories', 'Are you sure you want to log out {user}?', { user: this.user }),
          confirm: this.t('memories', 'Sign out'),
          confirmClasses: 'error',
          cancel: this.t('memories', 'Cancel'),
        })
      ) {
        nativex.logout();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
#memories-settings {
  :deep(.app-settings__content) {
    // Fix weirdness when focusing on toggle input on mobile
    position: relative;
  }

  :deep(input[readonly]) {
    cursor: pointer;
    user-select: none;
  }

  :deep(.app-settings-section) {
    margin-bottom: 20px !important;
  }

  :deep(#sign-out) {
    margin-top: 10px;
  }

  :deep(.checkbox-radio-switch__label) {
    padding: 1px 14px; // was 4px 14px, make it more compact
  }

  :deep(.radio-group) {
    margin-top: 6px;

    :deep(.title) {
      font-weight: 500;
    }

    :deep(.checkbox-radio-switch-radio) {
      margin: 2px 16px; // indent for radio button
    }
  }
}
</style>
