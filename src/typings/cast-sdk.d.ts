/**
 * Minimal typings for the parts of the Google Cast sender SDK we use.
 * https://developers.google.com/cast/docs/reference/web_sender
 */

interface Window {
  __onGCastApiAvailable?: (available: boolean) => void;
  cast?: typeof cast;
}

declare namespace chrome.cast {
  enum AutoJoinPolicy {
    TAB_AND_ORIGIN_SCOPED = 'tab_and_origin_scoped',
    ORIGIN_SCOPED = 'origin_scoped',
    PAGE_SCOPED = 'page_scoped',
  }
}

declare namespace chrome.cast.media {
  const DEFAULT_MEDIA_RECEIVER_APP_ID: string;

  enum PlayerState {
    IDLE = 'IDLE',
    PLAYING = 'PLAYING',
    PAUSED = 'PAUSED',
    BUFFERING = 'BUFFERING',
  }

  class GenericMediaMetadata {
    title?: string;
    subtitle?: string;
  }

  class PhotoMediaMetadata {
    title?: string;
    location?: string;
  }

  class MediaInfo {
    constructor(contentId: string, contentType: string);
    metadata: GenericMediaMetadata | PhotoMediaMetadata;
  }

  class LoadRequest {
    constructor(mediaInfo: MediaInfo);
  }

  class Media {
    playerState: PlayerState;
    /** Set when idle: FINISHED, ERROR, CANCELLED or INTERRUPTED */
    idleReason: string | null;
    addUpdateListener(listener: (isAlive: boolean) => void): void;
  }
}

declare namespace cast.framework {
  enum CastState {
    NO_DEVICES_AVAILABLE = 'NO_DEVICES_AVAILABLE',
    NOT_CONNECTED = 'NOT_CONNECTED',
    CONNECTING = 'CONNECTING',
    CONNECTED = 'CONNECTED',
  }

  enum SessionState {
    NO_SESSION = 'NO_SESSION',
    SESSION_STARTING = 'SESSION_STARTING',
    SESSION_STARTED = 'SESSION_STARTED',
    SESSION_START_FAILED = 'SESSION_START_FAILED',
    SESSION_ENDING = 'SESSION_ENDING',
    SESSION_ENDED = 'SESSION_ENDED',
    SESSION_RESUMED = 'SESSION_RESUMED',
  }

  enum CastContextEventType {
    CAST_STATE_CHANGED = 'caststatechanged',
    SESSION_STATE_CHANGED = 'sessionstatechanged',
  }

  type SessionStateEventData = { session: CastSession; sessionState: SessionState };

  class CastSession {
    getCastDevice(): { friendlyName: string };
    loadMedia(request: chrome.cast.media.LoadRequest): Promise<void>;
    getMediaSession(): chrome.cast.media.Media | null;
  }

  class CastContext {
    static getInstance(): CastContext;
    setOptions(options: { receiverApplicationId: string; autoJoinPolicy?: chrome.cast.AutoJoinPolicy }): void;
    getCastState(): CastState;
    getCurrentSession(): CastSession | null;
    requestSession(): Promise<void>;
    endCurrentSession(stopCasting: boolean): void;
    addEventListener(type: CastContextEventType.CAST_STATE_CHANGED, handler: () => void): void;
    addEventListener(
      type: CastContextEventType.SESSION_STATE_CHANGED,
      handler: (event: SessionStateEventData) => void,
    ): void;
  }
}
