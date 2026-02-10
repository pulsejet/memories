const euc = encodeURIComponent;

/** Access NativeX over localhost */
export const BASE_URL = 'http://127.0.0.1';

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
   * The native client MUST download the object using a download manager
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
};

/** NativeX synchronous API. */
export type NativeX = {
  /**
   * Check if the native interface is available.
   * @returns Should always return true.
   */
  isNative: () => boolean;

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
   * @param filename Filename to save as
   * @details An error must be shown to the user natively if the download fails.
   */
  downloadFromUrl: (url: string, filename: string) => void;

  /**
   * Set the list of objects to share with SHARE_BLOB API.
   * @param objects List of objects to share (JSON-encoded)
   * @details The SHARE_BLOB API must be called immediately after this call.
   */
  setShareBlobs: (objects: string) => void;

  /**
   * This signature is kept for backward compatibility.
   * Play a video from the given AUID or URL(s).
   * @param auid AUID of file (will play local if available)
   * @param fileid File ID of the video (only used for file tracking)
   * @param urlArray JSON-encoded array of URLs to play
   * @details The URL array may contain multiple URLs, e.g. direct playback
   * and HLS separately. The native client must try to play the first URL.
   */
  playVideo: (auid: string, fileid: number, urlArray: string) => void;

  /**
   * Play a video from the given AUID or URL(s).
   * @param auid AUID of file (will play local if available)
   * @param fileid File ID of the video (only used for file tracking)
   * @param urlArray JSON-encoded array of URLs to play
   * @param loop Whether the video should loop
   * @details The URL array may contain multiple URLs, e.g. direct playback
   * and HLS separately. The native client must try to play the first URL.
   */
  playVideo2: (auid: string, fileid: number, urlArray: string, loop?: boolean) => void;

  /**
   * Destroy the video player.
   * @param fileid File ID of the video
   * @details The native client must destroy the video player and free up resources.
   * If the fileid doesn't match the playing video, the call must be ignored.
   */
  destroyVideo: (fileid: number) => void;

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
   * Get the current sync status.
   * @returns number of file synced or -1
   */
  getSyncStatus: () => number;

  /**
   * Set if the given files have remote copies.
   * @param auid List of AUIDs to set the server ID for (JSON-encoded)
   * @param auid List of BUIDs to set the server ID for (JSON-encoded)
   * @param value Value of remote
   */
  setHasRemote: (auids: string, buids: string, value: boolean) => void;
};

/** The native interface is a global object that is injected by the native app. */
export const nativex: NativeX = (<any>globalThis).nativex;
