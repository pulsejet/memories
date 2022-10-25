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
        <label for="timeline-path">{{ t('memories', 'Timeline Path') }}</label>
        <input id="timeline-path"
            @click="chooseTimelinePath"
            v-model="config_timelinePath"
            type="text">

        <label for="folders-path">{{ t('memories', 'Folders Path') }}</label>
        <input id="folders-path"
            @click="chooseFoldersPath"
            v-model="config_foldersPath"
            type="text">

        <NcCheckboxRadioSwitch :checked.sync="config_showHidden"
            @update:checked="updateShowHidden"
            type="switch">
            {{ t('memories', 'Show hidden folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch :checked.sync="config_squareThumbs"
            @update:checked="updateSquareThumbs"
            type="switch">
            {{ t('memories', 'Square grid mode') }}
        </NcCheckboxRadioSwitch>
    </div>
</template>

<style scoped>
input[type=text] {
    width: 100%;
}
</style>

<script lang="ts">
import { Component, Mixins } from 'vue-property-decorator';
import GlobalMixin from '../mixins/GlobalMixin';

import { getFilePickerBuilder } from '@nextcloud/dialogs'
import UserConfig from '../mixins/UserConfig'

import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

@Component({
    components: {
        NcCheckboxRadioSwitch,
    },
})
export default class Settings extends Mixins(UserConfig, GlobalMixin) {
    async chooseFolder(title: string, initial: string) {
        const picker = getFilePickerBuilder(title)
				.setMultiSelect(false)
				.setModal(true)
				.setType(1)
				.addMimeTypeFilter('httpd/unix-directory')
				.allowDirectories()
				.startAt(initial)
				.build()

        return await picker.pick();
    }

    async chooseTimelinePath() {
        const newPath = await this.chooseFolder(this.t('memories', 'Choose the root of your timeline'), this.config_timelinePath);
        if (newPath !== this.config_timelinePath) {
            this.config_timelinePath = newPath;
            await this.updateSetting('timelinePath');
        }
    }

    async chooseFoldersPath() {
        const newPath = await this.chooseFolder(this.t('memories', 'Choose the root for the folders view'), this.config_foldersPath);
        if (newPath !== this.config_foldersPath) {
            this.config_foldersPath = newPath;
            await this.updateSetting('foldersPath');
        }
    }

    async updateSquareThumbs() {
        await this.updateSetting('squareThumbs');
    }

    async updateShowHidden() {
        await this.updateSetting('showHidden');
    }
}
</script>