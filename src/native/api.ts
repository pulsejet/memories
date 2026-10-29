// Leaf module: must not depend on anything at all (no imports).
// utils/helpers imports from here while sitting inside an import
// cycle with @native; any dependency added here risks a TDZ crash
// at module evaluation time.
const euc = encodeURIComponent;

/** Access NativeX over localhost */
export const BASE_URL = ((): string => {
  if (globalThis.location?.hostname === '127.0.0.1') {
    return globalThis.location.origin;
  }
  return 'http://127.0.0.1';
})();

/** NativeX asynchronous API */
export const NAPI = {
  /**
   * Login to Nextcloud server.
   * @regex ^/api/login/.+$
   * @param url URL to Nextcloud server
   */
  LOGIN: (url: string) => `${BASE_URL}/api/login/${euc(euc(url))}`,

  /**
   * Local days API.
   * @regex ^/api/days$
   * @returns {IDay[]} for all locally available days.
   */
  DAYS: () => `${BASE_URL}/api/days`,

  /**
   * Local photos API.
   * @regex ^/api/days/\d+$
   * @param dayId Day ID to fetch photos for
   * @returns {IPhoto[]} for all locally available photos for this day.
   */
  DAY: (dayId: number) => `${BASE_URL}/api/days/${dayId}`,

  /**
   * Local photo metadata API.
   * @regex ^/api/image/info/\d+$
   * @param fileId File ID of the photo
   * @returns {IImageInfo} for the given file ID (local).
   */
  IMAGE_INFO: (fileId: number) => `${BASE_URL}/api/image/info/${fileId}`,

  /**
   * Delete files using local fileids.
   * @regex ^/api/image/delete/\d+(,\d+)*$
   * @param fileIds List of AUIDs to delete
   * @param dry (Query) Only check for confirmation and count of local files
   * @returns {void}
   * @throws Return an error code if the user denies the deletion.
   */
  IMAGE_DELETE: (auids: string[]) => `${BASE_URL}/api/image/delete/${auids.join(',')}`,

  /**
   * Local photo preview API.
   * @regex ^/image/preview/\d+$
   * @param auid AUID of the photo
   * @returns {Blob} JPEG preview of the photo.
   */
  IMAGE_PREVIEW: (fileId: number) => `${BASE_URL}/image/preview/${fileId}`,
  /**
   * Local photo full API.
   * @regex ^/image/full/\d+$
   * @param auid AUID of the photo
   * @returns {Blob} JPEG full image of the photo.
   */
  IMAGE_FULL: (auid: string) => `${BASE_URL}/image/full/${auid}`,

  /**
   * Local video file API (range-aware, for <video>).
   * @regex ^/video/full/\d+$
   * @param fileId MediaStore file ID of the local video
   * @returns {Blob} Original video bytes of the photo.
   */
  VIDEO_FULL: (fileId: number) => `${BASE_URL}/video/full/${fileId}`,

  /**
   * Put an image into the native image view below the WebView.
   * POST raw image bytes (e.g. JPEG preview). Resolves only after
   * the native image view has rendered the image.
   * @regex ^/api/view/image$
   * @param seq Monotonic upload counter for ordering (query)
   * @param blob Image bytes
   * @returns {void}
   */
  VIEW_IMAGE: (seq: number) => `${BASE_URL}/api/view/image?seq=${seq}`,

  /**
   * Put the on-device copy of an indexed photo into the native view.
   * POST JSON {uri} with the MediaStore URI from the day response.
   * Resolves only after the native view has rendered the image.
   * Shares the upload sequence counter.
   * @regex ^/api/view/image/local$
   * @param seq Monotonic upload counter for ordering (query)
   * @param uri MediaStore content URI of the photo
   * @returns {void}
   */
  VIEW_IMAGE_LOCAL: (seq: number) => `${BASE_URL}/api/view/image/local?seq=${seq}`,

  /**
   * Upload a local file to Nextcloud natively, without routing
   * bytes through the WebView.
   * @regex ^/api/upload/local$
   * @param auid (Query) AUID of the local file
   * @param filename (Query) Destination path, e.g. /Photos/IMG_001.jpg
   * @returns {fileid} File ID of the uploaded file
   */
  UPLOAD_LOCAL: (auid: string, filename: string) =>
    `${BASE_URL}/api/upload/local?auid=${euc(auid)}&filename=${euc(filename)}`,

  /**
   * Share a URL with native page.
   * The native client MUST NOT download the object but share the URL directly.
   * @regex ^/api/share/url/.+$
   * @param url URL to share (double-encoded)
   * @returns {void}
   */
  SHARE_URL: (url: string) => `${BASE_URL}/api/share/url/${euc(euc(url))}`,
  /**
   * Share an object (as blob) natively.
   * The list of objects to share is already set using setShareBlobs
   * The native client MUST download the object in-process (never via the
   * system DownloadManager, which cannot reach LAN hosts on Android 17)
   * and immediately prompt the user to download it. The asynchronous call
   * must return only after the object has been downloaded.
   * @regex ^/api/share/blobs$
   * @returns {void}
   */
  SHARE_BLOBS: () => `${BASE_URL}/api/share/blobs`,

  /**
   * Allow usage of local media (permissions request)
   * @param val Allow or disallow media
   * @returns
   */
  CONFIG_ALLOW_MEDIA: (val: boolean) => `${BASE_URL}/api/config/allow_media/${val ? '1' : '0'}`,

  /**
   * Allow media management (opens system media-management-apps screen)
   * @regex ^/api/config/allow_manage_media$
   * @returns
   */
  CONFIG_ALLOW_MANAGE_MEDIA: () => `${BASE_URL}/api/config/allow_manage_media`,

  /**
   * Offline asset download progress.
   * @regex ^/api/assets/progress$
   * @returns {status, done, total, error} of the web asset sync.
   */
  ASSETS_PROGRESS: () => `${BASE_URL}/api/assets/progress`,
};

/** NativeX synchronous API. */
export type NativeX = {
  /**
   * Check if the native interface is available.
   * @returns Should always return true.
   */
  isNative: () => boolean;

  /** General setup after boot. */
  bootstrap?: () => void;

  /** Get the native platform. */
  getPlatform?: () => 'android' | 'ios';

  /** No-op on the sync bridge. */
  setupEventBus?: () => void;

  /**
   * Set the theme color of the app.
   * @param color Color to set
   * @param isDark Whether the theme is dark (for navigation bar)
   */
  setThemeColor: (color: string, isDark: boolean) => void;

  /**
   * Play a tap sound for UI interaction.
   */
  playTouchSound: () => void;

  /**
   * Vibrate for UI interaction.
   * @param duration Duration in milliseconds
   */
  vibrate?: (duration: number) => void;

  /**
   * Make a native toast to the user.
   * @param message Message to show
   * @param long Whether the toast should be shown for a long time
   */
  toast: (message: string, long?: boolean) => void;

  /**
   * Log out from Nextcloud and delete the tokens.
   */
  logout: () => void;

  /**
   * Reload the app.
   */
  reload: () => void;

  /**
   * Start downloading a file from a given URL.
   * @param url URL to download from
   * @param filename Filename to save as (empty: infer from response)
   * @param title Optional label for the completion notification (e.g. album name)
   * @details An error must be shown to the user natively if the download fails.
   */
  downloadFromUrl: (url: string, filename: string, title?: string) => void;

  /**
   * Set the list of objects to share with SHARE_BLOB API.
   * @param objects List of objects to share (JSON-encoded)
   * @details The SHARE_BLOB API must be called immediately after this call.
   */
  setShareBlobs: (objects: string) => void;

  /**
   * Set the local folders configuration to show in the timeline.
   * @param json JSON-encoded array of LocalFolderConfig
   */
  configSetLocalFolders: (json: string) => void;

  /**
   * Get the local folders configuration to show in the timeline.
   * @returns JSON-encoded array of LocalFolderConfig
   */
  configGetLocalFolders: () => string;

  /**
   * Check if the user has allowed media access.
   * @returns Whether the user has allowed media access.
   */
  configHasMediaPermission: () => boolean;

  /**
   * Check if silent local delete is allowed (no OS prompt).
   * @returns Whether the app is a media management app.
   */
  canManageMedia?: () => boolean;

  /** Playable video codecs for ExoPlayer as JSON array of ffprobe names. */
  getPlayableVideoCodecs?: () => string;

  /**
   * Get the current sync status.
   * @returns number of file synced or -1
   */
  getSyncStatus: () => number;

  /**
   * Set if the given files have remote copies.
   * @param auids List of AUIDs to set the server ID for (JSON-encoded)
   * @param buids List of BUIDs to set the server ID for (JSON-encoded)
   * @param value Value of remote
   */
  setHasRemote: (auids: string, buids: string, value: boolean) => void;

  /**
   * Control the native hole video player (play/pause/seek/volume/mute/rate).
   * @param json JSON-encoded NativeVideoCommand
   */
  nativeVideoCommand: (json: string) => void;

  /** Show/hide the native image below the WebView. */
  showNativeImage?: (show: boolean) => void;

  /** Show/hide the native video below the WebView. */
  showNativeVideo?: (show: boolean) => void;

  /**
   * Toggle HDR color mode for the activity window.
   * @param enabled True for HDR, false for default
   */
  setHdrColorMode?: (enabled: boolean) => void;

  /**
   * Move/resize the native dummy image to the hole rect.
   * @param x Left in CSS px
   * @param y Top in CSS px
   * @param w Width in CSS px
   * @param h Height in CSS px
   */
  setNativeImageRect?: (x: number, y: number, w: number, h: number) => void;

  /**
   * Move/resize the native hole video to the hole rect.
   * @param x Left in CSS px
   * @param y Top in CSS px
   * @param w Width in CSS px
   * @param h Height in CSS px
   */
  setNativeVideoRect?: (x: number, y: number, w: number, h: number) => void;
};

/** The native interface is a global object that is injected by the native app. */
export const nativex: NativeX = (<any>globalThis).nativex;

/**
 * @returns Whether the native interface is available.
 */
export function has() {
  return !!nativex;
}

/** NativeX asynchronous (fire-and-forget) API over WebMessage. */
export type NativeXAsync = Pick<
  NativeX,
  | 'bootstrap'
  | 'setupEventBus'
  | 'setThemeColor'
  | 'playTouchSound'
  | 'vibrate'
  | 'toast'
  | 'setHasRemote'
  | 'showNativeImage'
  | 'setNativeImageRect'
  | 'showNativeVideo'
  | 'setNativeVideoRect'
  | 'nativeVideoCommand'
  | 'setHdrColorMode'
>;

/** Get a fire-and-forget caller for one async method. */
export function nxAsync<M extends keyof NativeXAsync>(method: M): NativeXAsync[M] | null {
  // Feature detection: all async methods exist on the sync handler.
  if (!nativex?.[method]) return null;

  // Return a function that posts a message to the native app.
  return (...args: unknown[]) => {
    // The async native interface for fire-and-forget interactions.
    const nativexAsync = (<any>globalThis).nativex_async;
    // Legacy compatibility when the async bridge is not found.
    if (!nativexAsync) (nativex[method] as (...a: unknown[]) => void)(...args);
    // Modern async bridge: post a message to the native app.
    else nativexAsync.postMessage(JSON.stringify({ method, args }));
  };
}
