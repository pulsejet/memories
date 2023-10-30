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

import { createClient } from 'webdav';
import { getRequestToken, onRequestTokenUpdate } from '@nextcloud/auth';
import { generateRemoteUrl } from '@nextcloud/router';

// init webdav client on default dav endpoint
const remote = generateRemoteUrl('dav');
const client = createClient(remote);

// set CSRF token header
function setHeaders(token: string | null) {
  client.setHeaders({
    // Add this so the server knows it is an request from the browser
    'X-Requested-With': 'XMLHttpRequest',
    // Inject user auth
    requesttoken: token ?? String(),
  });
}

// refresh headers when request token changes
setHeaders(getRequestToken());
onRequestTokenUpdate((t) => setHeaders(t));

// Filenames start with this path
export const remotePath = new URL(remote).pathname;

// Get the current client
export default client;
