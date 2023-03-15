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
      :open="open"
      :show-navigation="true"
      :title="t('memories', 'Memories Settings')"
      @update:open="onClose"
    >
      <NcAppSettingsSection
        id="general-settings"
        :title="t('memories', 'General')"
      >
        <label for="timeline-path">{{ t("memories", "Timeline Path") }}</label>
        <input
          id="timeline-path"
          @click="chooseTimelinePath"
          v-model="config_timelinePath"
          type="text"
        />

        <NcCheckboxRadioSwitch
          :checked.sync="config_squareThumbs"
          @update:checked="updateSquareThumbs"
          type="switch"
        >
          {{ t("memories", "Square grid mode") }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config_enableTopMemories"
          @update:checked="updateEnableTopMemories"
          type="switch"
        >
          {{ t("memories", "Show past photos on top of timeline") }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config_fullResOnZoom"
          @update:checked="updateFullResOnZoom"
          type="switch"
        >
          {{ t("memories", "Load full size image on zoom") }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config_fullResAlways"
          @update:checked="updateFullResAlways"
          type="switch"
        >
          {{ t("memories", "Always load full size image (not recommended)") }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection
        id="folders-settings"
        :title="t('memories', 'Folders')"
      >
        <label for="folders-path">{{ t("memories", "Folders Path") }}</label>
        <input
          id="folders-path"
          @click="chooseFoldersPath"
          v-model="config_foldersPath"
          type="text"
        />

        <NcCheckboxRadioSwitch
          :checked.sync="config_showHidden"
          @update:checked="updateShowHidden"
          type="switch"
        >
          {{ t("memories", "Show hidden folders") }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch
          :checked.sync="config_sortFolderMonth"
          @update:checked="updateSortFolderMonth"
          type="switch"
        >
          {{ t("memories", "Sort folders oldest-first") }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>

      <NcAppSettingsSection
        id="albums-settings"
        :title="t('memories', 'Albums')"
      >
        <NcCheckboxRadioSwitch
          :checked.sync="config_sortAlbumMonth"
          @update:checked="updateSortAlbumMonth"
          type="switch"
        >
          {{ t("memories", "Sort albums oldest-first") }}
        </NcCheckboxRadioSwitch>
      </NcAppSettingsSection>
    </NcAppSettingsDialog>

    <MultiPathSelectionModal
      ref="multiPathModal"
      :title="pathSelTitle"
      @close="saveTimelinePath"
    />
  </div>
</template>

<style scoped>
input[type="text"] {
  width: 100%;
}
</style>

<script lang="ts">
import { defineComponent } from "vue";

import { getFilePickerBuilder } from "@nextcloud/dialogs";

import UserConfig from "../mixins/UserConfig";
const NcAppSettingsDialog = () =>
  import("@nextcloud/vue/dist/Components/NcAppSettingsDialog");
const NcAppSettingsSection = () =>
  import("@nextcloud/vue/dist/Components/NcAppSettingsSection");
const NcCheckboxRadioSwitch = () =>
  import("@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch");

import MultiPathSelectionModal from "./modal/MultiPathSelectionModal.vue";

export default defineComponent({
  name: "Settings",

  components: {
    NcAppSettingsDialog,
    NcAppSettingsSection,
    NcCheckboxRadioSwitch,
    MultiPathSelectionModal,
  },

  mixins: [UserConfig],

  props: {
    open: {
      type: Boolean,
      required: true,
    },
  },

  computed: {
    pathSelTitle(): string {
      return this.t("memories", "Choose Timeline Paths");
    },
  },

  methods: {
    onClose() {
      this.$emit("update:open", false);
    },

    async chooseFolder(title: string, initial: string) {
      const picker = getFilePickerBuilder(title)
        .setMultiSelect(false)
        .setModal(true)
        .setType(1)
        .addMimeTypeFilter("httpd/unix-directory")
        .allowDirectories()
        .startAt(initial)
        .build();

      return await picker.pick();
    },

    async chooseTimelinePath() {
      (<any>this.$refs.multiPathModal).open(
        this.config_timelinePath.split(";")
      );
    },

    async saveTimelinePath(paths: string[]) {
      if (!paths || !paths.length) return;

      const newPath = paths.join(";");
      if (newPath !== this.config_timelinePath) {
        this.config_timelinePath = newPath;
        await this.updateSetting("timelinePath");
      }
    },

    async chooseFoldersPath() {
      let newPath = await this.chooseFolder(
        this.t("memories", "Choose the root for the folders view"),
        this.config_foldersPath
      );
      if (newPath === "") newPath = "/";
      if (newPath !== this.config_foldersPath) {
        this.config_foldersPath = newPath;
        await this.updateSetting("foldersPath");
      }
    },

    async updateSquareThumbs() {
      await this.updateSetting("squareThumbs");
    },

    async updateFullResOnZoom() {
      await this.updateSetting("fullResOnZoom");
    },

    async updateFullResAlways() {
      await this.updateSetting("fullResAlways");
    },

    async updateEnableTopMemories() {
      await this.updateSetting("enableTopMemories");
    },

    async updateShowHidden() {
      await this.updateSetting("showHidden");
    },

    async updateSortFolderMonth() {
      await this.updateSetting("sortFolderMonth");
    },

    async updateSortAlbumMonth() {
      await this.updateSetting("sortAlbumMonth");
    },
  },
});
</script>
