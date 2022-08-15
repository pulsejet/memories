/**
 * @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

 import * as webdav from 'webdav'
 import axios from '@nextcloud/axios'
 import parseUrl from 'url-parse'
 import { generateRemoteUrl } from '@nextcloud/router'

 // Monkey business
 import * as rq from 'webdav/dist/node/request';
 rq.prepareRequestOptionsOld = rq.prepareRequestOptions.bind(rq);
 rq.prepareRequestOptions = (function(requestOptions, context, userOptions) {
    requestOptions.method = userOptions.method || requestOptions.method;
    return this.prepareRequestOptionsOld(requestOptions, context, userOptions);
 }).bind(rq);

 // force our axios
 const patcher = webdav.getPatcher()
 patcher.patch('request', axios)

 // init webdav client on default dav endpoint
 const remote = generateRemoteUrl('dav')
 const client = webdav.createClient(remote)

 export const remotePath = parseUrl(remote).pathname
 export default client