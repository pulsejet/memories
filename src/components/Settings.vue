<!--
 - @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
 -
 - @author John Molakvoæ <skjnldsv@protonmail.com>
 -
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
  <div>
    <NcAppSettingsDialog
      id="memories-settings"
      :open="open"
      :show-navigation="true"
      :title="t('memories', 'Memories Settings')"
      @update:open="onClose"
    >
      <NcAppSettingsSection id="general-settings" :title="t('memories', 'General')">
        <label for="timeline-path">{{ t('memories', 'Timeline Path') }}</label>
        <input id="timeline-path" @click="chooseTimelinePath" v-model="config.timeline_path" type="text" readonly />

        <NcCheckboxRadioSwitch :checked.sync="config.square_thumbs" @update:checked="updateSquareThumbs" type="switch">
          {{ t('memories', 'Square grid mode') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.enable_top_memories"
          @update:checked="updateEnableTopMemories"
          type="switch"
        >
          {{ t('memories', 'Show past photos on top of timeline') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.full_res_on_zoom"
          @update:checked="updateFullResOnZoom"
          type="switch"
        >
          {{ t('memories', 'Load full size image on zoom') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.full_res_always"
          @update:checked="updateFullResAlways"
          type="switch"
        >
          {{ t('memories', 'Always load full size image (not recommended)') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="account-settings" :title="t('memories', 'Account')" v-if="isNative">
        {{ t('memories', 'Logged in as {user}', { user }) }}
        <NcButton @click="logout" id="sign-out">
          {{ t('memories', 'Sign out') }}
        </NcButton>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="device-settings" :title="t('memories', 'Device Folders')" v-if="isNative">
        {{ t('memories', 'Local folders to include in the timeline view') }}
        <NcCheckboxRadioSwitch
          v-for="folder in localFolders"
          :key="folder.id"
          :checked.sync="folder.enabled"
          @update:checked="updateDeviceFolders"
          type="switch"
        >
          {{ folder.name }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="folders-settings" :title="t('memories', 'Folders')">
        <label for="folders-path">{{ t('memories', 'Folders Path') }}</label>
        <input id="folders-path" @click="chooseFoldersPath" v-model="config.folders_path" type="text" />

        <NcCheckboxRadioSwitch
          :checked.sync="config.show_hidden_folders"
          @update:checked="updateShowHidden"
          type="switch"
        >
          {{ t('memories', 'Show hidden folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config.sort_folder_month"
          @update:checked="updateSortFolderMonth"
          type="switch"
        >
          {{ t('memories', 'Sort folders oldest-first') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection id="albums-settings" :title="t('memories', 'Albums')">
        <NcCheckboxRadioSwitch
          :checked.sync="config.sort_album_month"
          @update:checked="updateSortAlbumMonth"
          type="switch"
        >
          {{ t('memories', 'Sort albums oldest-first') }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>
    </NcAppSettingsDialog>

    <MultiPathSelectionModal ref="multiPathModal" :title="pathSelTitle" @close="saveTimelinePath" />
  </div>
</template>

<style scoped>
input[type='text'] {
  width: 100%;
}
</style>

<script lang="ts">
import { defineComponent } from 'vue';

import { getFilePickerBuilder } from '@nextcloud/dialogs';
import { getCurrentUser } from '@nextcloud/auth';

import UserConfig from '../mixins/UserConfig';
import * as nativex from '../native';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcAppSettingsDialog = () => import('@nextcloud/vue/dist/Components/NcAppSettingsDialog');
const NcAppSettingsSection = () => import('@nextcloud/vue/dist/Components/NcAppSettingsSection');
const NcCheckboxRadioSwitch = () => import('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch');

import MultiPathSelectionModal from './modal/MultiPathSelectionModal.vue';

export default defineComponent({
  name: 'Settings',

  components: {
    NcButton,
    NcAppSettingsDialog,
    NcAppSettingsSection,
    NcCheckboxRadioSwitch,
    MultiPathSelectionModal,
  },

  mixins: [UserConfig],

  data: () => ({
    localFolders: [] as nativex.LocalFolderConfig[],
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
      return getCurrentUser()?.uid.toString() ?? '';
    },
  },

  mounted() {
    if (this.isNative) {
      this.refreshNativeConfig();
    }
  },

  methods: {
    onClose() {
      this.$emit('update:open', false);
    },

    logout() {
      nativex.logout();
    },

    async chooseFolder(title: string, initial: string) {
      const picker = getFilePickerBuilder(title)
        .setMultiSelect(false)
        .setModal(true)
        .setType(1)
        .addMimeTypeFilter('httpd/unix-directory')
        .allowDirectories()
        .startAt(initial)
        .build();

      return await picker.pick();
    },

    async chooseTimelinePath() {
      (<any>this.$refs.multiPathModal).open(this.config.timeline_path.split(';'));
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
      let newPath = await this.chooseFolder(
        this.t('memories', 'Choose the root for the folders view'),
        this.config.folders_path
      );
      if (newPath === '') newPath = '/';
      if (newPath !== this.config.folders_path) {
        this.config.folders_path = newPath;
        await this.updateSetting('folders_path', 'foldersPath');
      }
    },

    async updateSquareThumbs() {
      await this.updateSetting('square_thumbs');
    },

    async updateFullResOnZoom() {
      await this.updateSetting('full_res_on_zoom');
    },

    async updateFullResAlways() {
      await this.updateSetting('full_res_always');
    },

    async updateEnableTopMemories() {
      await this.updateSetting('enable_top_memories', 'enableTopMemories');
    },

    async updateShowHidden() {
      await this.updateSetting('show_hidden_folders', 'showHidden');
    },

    async updateSortFolderMonth() {
      await this.updateSetting('sort_folder_month', 'sortFolderMonth');
    },

    async updateSortAlbumMonth() {
      await this.updateSetting('sort_album_month', 'sortAlbumMonth');
    },

    // --------------- Native APIs start -----------------------------
    async refreshNativeConfig() {
      this.localFolders = await nativex.getLocalFolders();
    },

    async updateDeviceFolders() {
      await nativex.setLocalFolders(this.localFolders);
    },
  },
});
</script>

<style lang="scss" scoped>
#memories-settings:deep {
  .app-settings__content {
    // Fix weirdness when focusing on toggle input on mobile
    position: relative;
  }

  .app-settings-section {
    margin-bottom: 20px !important;
  }

  #sign-out {
    margin-top: 10px;
  }
}
</style>
