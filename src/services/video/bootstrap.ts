/**
 * Generate a random client id for this instance of the application.
 */
function generateClientId(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Bootstrap the client id in the global object.
 */
export function bootstrapVideo() {
  // Generate client id for this instance
  _m.video.clientId = generateClientId();

  // Retrieve the persistent client id from local storage
  const clientIdKey = 'videoClientIdPersistent';
  const oldClientId = localStorage.getItem(clientIdKey) ?? _m.video.clientId;
  _m.video.clientIdPersistent = /^[0-9a-f]{32}$/.test(oldClientId) ? oldClientId : _m.video.clientId;
  localStorage.setItem(clientIdKey, _m.video.clientIdPersistent);
}
