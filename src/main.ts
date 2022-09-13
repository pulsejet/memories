/**
 * @copyright Copyright (c) 2018 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
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
import 'reflect-metadata'
import Vue from 'vue'
import VueVirtualScroller from 'vue-virtual-scroller'
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css'

import App from './App.vue'
import router from './router'

Vue.use(VueVirtualScroller)

// https://github.com/nextcloud/photos/blob/156f280c0476c483cb9ce81769ccb0c1c6500a4e/src/main.js
// TODO: remove when we have a proper fileinfo standalone library
// original scripts are loaded from
// https://github.com/nextcloud/server/blob/5bf3d1bb384da56adbf205752be8f840aac3b0c5/lib/private/legacy/template.php#L120-L122
window.addEventListener('DOMContentLoaded', () => {
    if (!globalThis.OCA.Files) {
        globalThis.OCA.Files = {}
    }
    // register unused client for the sidebar to have access to its parser methods
    Object.assign(globalThis.OCA.Files, { App: { fileList: { filesClient: globalThis.OC.Files.getClient() } } }, globalThis.OCA.Files)
})

export default new Vue({
    el: '#content',
    router,
    render: h => h(App),
})
