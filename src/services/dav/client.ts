import { createClient } from 'webdav';
import { getRequestToken, onRequestTokenUpdate } from '@nextcloud/auth';
import { generateRemoteUrl } from '@nextcloud/router';
import { onRecognizeApiKeyUpdate } from './recognize';

// init webdav client on default dav endpoint
const remote = generateRemoteUrl('dav');
const client = createClient(remote);

// webdav coerces numeric-looking values, e.g. the user ID '1e5' to 100000
const STRING_PROPS = ['prop.location', 'prop.collaborators.collaborator.id', 'prop.collaborators.collaborator.label'];
client.registerTagParser((path, value) => (STRING_PROPS.some((prop) => path.endsWith(prop)) ? undefined : value));

// Cached header values
const headerState: {
  token?: string;
  recognizeApiKey?: string;
} = {};

// set CSRF token header
function setHeaders() {
  const headers: Record<string, string> = {
    // Add this so the server knows it is an request from the browser
    'X-Requested-With': 'XMLHttpRequest',
    // Inject CSRF token into the request headers
    requesttoken: headerState.token ?? getRequestToken() ?? String(),
  };

  // API Key for Recognize, if available
  if (headerState.recognizeApiKey) {
    headers['X-Recognize-Api-Key'] = headerState.recognizeApiKey;
  }

  client.setHeaders(headers);
}

// do the initial header setup
setHeaders();

// refresh headers when request token changes
onRequestTokenUpdate((token) => {
  headerState.token = token;
  setHeaders();
});

// fetch the Recognize API key once and refresh headers
onRecognizeApiKeyUpdate((key) => {
  headerState.recognizeApiKey = key;
  setHeaders();
});

// Filenames start with this path
export const remotePath = new URL(remote).pathname;

// Get the current client
export default client;
