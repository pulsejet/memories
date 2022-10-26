/**
 * @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import { Component, Vue } from 'vue-property-decorator';
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

const eventName = 'memories:user-config-changed'
const localSettings = ['squareThumbs', 'showFaceRect'];

@Component
export default class UserConfig extends Vue {
    config_timelinePath: string = loadState('memories', 'timelinePath') || '';
    config_foldersPath: string = loadState('memories', 'foldersPath') || '/';
    config_showHidden = loadState('memories', 'showHidden') === "true";

    config_tagsEnabled = Boolean(loadState('memories', 'systemtags'));
    config_recognizeEnabled = Boolean(loadState('memories', 'recognize'));
    config_mapsEnabled = Boolean(loadState('memories', 'maps'));

    config_squareThumbs = localStorage.getItem('memories_squareThumbs') === '1';
    config_showFaceRect = localStorage.getItem('memories_showFaceRect') === '1';

    config_eventName = eventName;

    created() {
        subscribe(eventName, this.updateLocalSetting)
    }

    beforeDestroy() {
        unsubscribe(eventName, this.updateLocalSetting)
    }

    updateLocalSetting({ setting, value }) {
        this['config_' + setting] = value
    }

    async updateSetting(setting: string) {
        const value = this['config_' + setting]

        if (localSettings.includes(setting)) {
            if (typeof value === 'boolean') {
                localStorage.setItem('memories_' + setting, value ? '1' : '0')
            } else {
                localStorage.setItem('memories_' + setting, value)
            }
        } else {
            // Long time save setting
            await axios.put(generateUrl('apps/memories/api/config/' + setting), {
                value: value.toString(),
            });
        }

        // Visible elements update setting
        emit(eventName, { setting, value });
    }
}