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
            v-model="config_timelinePath"
            type="text">

        <NcCheckboxRadioSwitch :checked.sync="config_showHidden"
            type="switch">
            {{ t('memories', 'Show hidden folders') }}
        </NcCheckboxRadioSwitch>

        <NcCheckboxRadioSwitch :checked.sync="config_squareThumbs"
            type="switch">
            {{ t('memories', 'Square grid mode') }}
        </NcCheckboxRadioSwitch>

        <button @click="updateAll()">
            {{ t('memories', 'Update') }}
        </button>
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

import { showError } from '@nextcloud/dialogs'
import UserConfig from '../mixins/UserConfig'

import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

@Component({
    components: {
        NcCheckboxRadioSwitch,
    },
})
export default class Settings extends Mixins(UserConfig, GlobalMixin) {
    async updateAll() {
        // Update localStorage
        localStorage.setItem('memories_squareThumbs', this.config_squareThumbs ? '1' : '0');

        // Update remote
        await this.updateSetting('showHidden');
        const res = await this.updateSetting('timelinePath');
        if (res.status === 200) {
            window.location.reload();
        } else {
            showError(this.t('memories', 'Error updating settings'));
        }
    }
}
</script>