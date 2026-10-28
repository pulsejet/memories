declare module '@typings' {
  /** Single quality level exposed to the video controls. */
  export interface QualityOption {
    id: string;
    size: number;
    bitrate?: number;
  }

  /**
   * Minimal surface of HTMLVideoElement used by the video controls.
   *
   * HTMLVideoElement satisfies this interface, so the DOM player can be
   * passed through as-is. A custom (e.g. native) renderer only needs to
   * provide these members plus the mapped events so the existing controls
   * keep working with a shim.
   */
  export interface VideoController
    extends
      EventTarget,
      Pick<
        HTMLMediaElement,
        | 'paused'
        | 'currentTime'
        | 'duration'
        | 'volume'
        | 'muted'
        | 'playbackRate'
        | 'readyState'
        | 'buffered'
        | 'play'
        | 'pause'
        | 'load'
        | 'loop'
      >,
      Partial<Pick<HTMLMediaElement, 'preload' | 'autoplay'>>,
      Partial<
        Pick<HTMLVideoElement, 'videoWidth' | 'videoHeight' | 'disablePictureInPicture' | 'requestPictureInPicture'>
      > {
    /** Element traversal used to find the containing .video-container. */
    closest?: Element['closest'];

    /** Safari legacy fullscreen. */
    webkitEnterFullscreen?: () => void;
    webkitSupportsFullscreen?: boolean;

    /** Safari legacy PiP. */
    webkitSetPresentationMode?: (mode: 'picture-in-picture' | 'inline') => void;
    webkitPresentationMode?: string;
    webkitSupportsPresentationMode?: (mode: string) => boolean;

    /** HLS quality selection. Absent when unsupported. */
    currentQuality?: string;
    readonly qualityList?: QualityOption[];

    addEventListener<K extends keyof VideoControllerEventMap>(
      type: K,
      listener: (this: VideoController, ev: VideoControllerEventMap[K]) => void,
      options?: boolean | AddEventListenerOptions,
    ): void;
    removeEventListener<K extends keyof VideoControllerEventMap>(
      type: K,
      listener: (this: VideoController, ev: VideoControllerEventMap[K]) => void,
      options?: boolean | EventListenerOptions,
    ): void;
  }

  /** DOM media/PiP events plus the custom/webkit events the controls use. */
  export interface VideoControllerEventMap extends HTMLVideoElementEventMap {
    'quality-list': CustomEvent;
    'quality-selected': CustomEvent;
    webkitbeginfullscreen: Event;
    webkitendfullscreen: Event;
    webkitpresentationmodechanged: Event;
  }

  /** Closed set of event names the controls subscribe to (DOM media + PiP + custom quality). */
  export type VideoControllerEventType = keyof VideoControllerEventMap;

  /** Backing player for a video slide: an element behind the controls surface. */
  export type VideoControllerElement = HTMLElement & VideoController;

  // Compile-time check: the DOM player must stay assignable to VideoController.
  type _AssertTrue<T extends true> = T;
  type _HtmlVideoIsVideoController = _AssertTrue<HTMLVideoElement extends VideoController ? true : false>;

  /** Snapshot of the native hole video player. */
  export interface NativeXVideoState {
    url: string | null; // current

    paused: boolean;
    playing: boolean;
    ended: boolean;
    seeking: boolean; // seek in-flight

    currentTime: number;
    duration: number | null;

    volume: number;
    muted: boolean;
    playbackRate: number;

    readyState: HTMLMediaElement['readyState'];
    videoWidth: number | null;
    videoHeight: number | null;
    bufferedEnd: number;

    error: string | null; // last error
  }
}
