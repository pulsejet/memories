import { createClient } from 'webdav';
import { getRequestToken, onRequestTokenUpdate } from '@nextcloud/auth';
import { generateRemoteUrl } from '@nextcloud/router';
import { initstate } from '@services/utils';

// init webdav client on default dav endpoint
const remote = generateRemoteUrl('dav');
const client = createClient(remote);
const { recognizeApiKey } = initstate;

// set CSRF token header
function setHeaders(token: string | null) {
  client.setHeaders({
    // Add this so the server knows it is an request from the browser
    'X-Requested-With': 'XMLHttpRequest',
    // API Key for Recognize
    'X-Recognize-Api-Key': recognizeApiKey,
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
