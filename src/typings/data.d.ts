declare module '@typings' {
  export type IFileInfo = {
    /** Same as fileid */
    id: number;
    /** Database file ID */
    fileid: number;
    /** Full file name, e.g. /pi/test/Qx0dq7dvEXA.jpg */
    filename: string;
    /** Original file name, e.g. /files/admin/pi/test/Qx0dq7dvEXA.jpg */
    originalFilename: string;
    /** Base name of file e.g. Qx0dq7dvEXA.jpg */
    basename: string;
  };

  export type IDay = {
    /** Day ID */
    dayid: number;
    /** Number of photos in this day */
    count: number;
    /** Rows in the day */
    rows?: IRow[];
    /** List of photos for this day */
    detail?: IPhoto[];
    /** This day has some local photos */
    haslocal?: boolean;
  };

  export type IPhoto = {
    /** Nextcloud ID of file */
    fileid: number;
    /**
     * Vue key unique to this object.
     * 1/ File ID by default.
     * 2/ Indexed if duplicates present.
     * 3/ Face ID for people views.
     */
    key?: string;
    /** Etag from server */
    etag?: string;
    /** Base name of file */
    basename?: string;
    /** Mime type of file */
    mimetype?: string;
    /** Bit flags */
    flag: number;
    /** DayID from server */
    dayid: number;
    /** Width of full image */
    w?: number;
    /** Height of full image */
    h?: number;
    /** Live Photo identifier */
    liveid?: string;
    /** File owner display name */
    shared_by?: string;

    /** Grid display width px */
    dispW?: number;
    /** Grid display height px */
    dispH?: number;
    /** Grid display X px */
    dispX?: number;
    /** Grid display Y px */
    dispY?: number;
    /** Grid display row id (relative to head) */
    dispRowNum?: number;

    /** Reference to day object */
    d?: IDay;
    /** Reference to exif object */
    imageInfo?: IImageInfo | null;

    /** Face detection ID */
    faceid?: number;
    /** Face dimensions */
    facerect?: IFaceRect;

    /** Video flag from server */
    isvideo?: boolean;
    /** Video duration from server */
    video_duration?: number;
    /** Favorite flag from server */
    isfavorite?: boolean;
    /** Local file from native */
    islocal?: boolean;
    /**
     * Photo is hidden from timeline; discard immediately.
     * This field exists so that we can merge with locals.
     */
    ishidden?: boolean;

    /** AUID of file (optional, NativeX) */
    auid?: string;
    /** BUID of file (optional, NativeX) */
    buid?: string;
    /** Epoch of file (optional, NativeX) */
    epoch?: number;

    /** Date taken UTC value (lazy fetched) */
    datetaken?: number;

    /** Stacked RAW photos */
    stackraw?: IPhoto[];
  };

  export interface IImageInfo {
    fileid: number;
    etag: string;
    h: number;
    w: number;
    datetaken: number;

    permissions: string;
    basename: string;
    mimetype: string;
    size: number;
    uploadtime: number;

    owneruid: string;
    ownername: string;

    filename?: string;
    address?: string;
    tags?: { [id: string]: string };

    exif?: IExif;

    clusters?: {
      albums?: IAlbum[];
      recognize?: IFace[];
      facerecognition?: IFace[];
    };
  }

  export interface IExif {
    Rotation?: number;
    Orientation?: number;
    ImageWidth?: number;
    ImageHeight?: number;
    Megapixels?: number;

    Title?: string;
    Description?: string;
    Make?: string;
    Model?: string;

    CreateDate?: string;
    DateTimeOriginal?: string;
    DateTimeEpoch?: number;
    OffsetTimeOriginal?: string;
    OffsetTime?: string;
    LocationTZID?: string;
    AllDates?: string; // only for setting

    ExposureTime?: number;
    ShutterSpeed?: number;
    ShutterSpeedValue?: number;
    Aperture?: number;
    ApertureValue?: number;
    ISO?: number;
    FNumber?: number;
    FocalLength?: number;

    GPSAltitude?: number;
    GPSLatitude?: number;
    GPSLongitude?: number;
  }
}
