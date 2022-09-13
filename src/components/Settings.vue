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
            v-model="timelinePath"
            type="text">

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
import { Component, Mixins, Vue } from 'vue-property-decorator';
import UserConfig from '../mixins/UserConfig'

@Component({
    mixins: [
        UserConfig,
    ],
})
export default class Settings extends Mixins(UserConfig) {
    async updateAll() {
        const res = await this.updateSetting('timelinePath');
        if (res.status === 200) {
            window.location.reload();
        } else {
            alert('Error updating settings');
        }
    }
}
</script>