import { VueConstructor } from "vue";

export type IFileInfo = {
  /** Database file ID */
  fileid: number;
  /** Full file name, e.g. /pi/test/Qx0dq7dvEXA.jpg */
  filename: string;
  /** Original file name, e.g. /files/admin/pi/test/Qx0dq7dvEXA.jpg */
  originalFilename?: string;
  /** Base name of file e.g. Qx0dq7dvEXA.jpg */
  basename: string;
  /** Etag identifier */
  etag: string;
  /** File has preview available */
  hasPreview: boolean;
  /** File is marked favorite */
  favorite?: boolean;
  /** Vue flags */
  flag?: number;
  /** MIME type of file */
  mime?: string;
  /** WebDAV permissions string */
  permissions?: string;
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
};

export type IPhoto = {
  /** Nextcloud ID of file */
  fileid: number;
  /** Vue key if duplicates present (otherwise use fileid) */
  key?: string;
  /** Etag from server */
  etag?: string;
  /** Path to file */
  filename?: string;
  /** Base name of file */
  basename?: string;
  /** Mime type of file */
  mimetype?: string;
  /** Bit flags */
  flag: number;
  /** DayID from server */
  dayid?: number;
  /** Width of full image */
  w?: number;
  /** Height of full image */
  h?: number;

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

  /** Face dimensions */
  facerect?: IFaceRect;

  /** Video flag from server */
  isvideo?: boolean;
  /** Favorite flag from server */
  isfavorite?: boolean;
  /** Is this a folder */
  isfolder?: boolean;
  /** Is this a tag */
  istag?: boolean;
  /** Is this an album */
  isalbum?: boolean;
  /** Is this a face */
  isface?: boolean;
  /** Optional datetaken epoch */
  datetaken?: number;
};

export interface IFolder extends IPhoto {
  /** Path to folder */
  path: string;
  /** FileInfos for preview images */
  previewFileInfos?: IFileInfo[];
  /** Name of folder */
  name: string;
}

export interface ITag extends IPhoto {
  /** Name of tag */
  name: string;
  /** Number of images in this tag */
  count: number;
  /** User for face if face */
  user_id?: string;
  /** Cache of previews */
  previews?: IPhoto[];
}

export interface IAlbum extends ITag {
  /** ID of album */
  album_id: number;
  /** Owner of album */
  user: string;
  /** Created timestamp */
  created: number;
  /** Location string */
  location: string;
  /** File ID of last added photo */
  last_added_photo: number;
}

export interface IFaceRect {
  w: number;
  h: number;
  x: number;
  y: number;
}

export type IRow = {
  /** Vue Recycler identifier */
  id?: string;
  /** Row ID from head */
  num: number;
  /** Day ID */
  dayId: number;
  /** Refrence to day object */
  day: IDay;
  /** Whether this is a head row */
  type: IRowType;
  /** [Head only] Title of the header */
  name?: string;
  /** [Head only] Boolean if the entire day is selected */
  selected?: boolean;
  /** Main list of photo items */
  photos?: IPhoto[];
  /** Height in px of the row */
  size?: number;
  /** Count of placeholders to create */
  pct?: number;
};
export type IHeadRow = IRow & {
  type: IRowType.HEAD;
  selected: boolean;
  super?: string;
};
export enum IRowType {
  HEAD = 0,
  PHOTOS = 1,
  FOLDERS = 2,
}

export type ITick = {
  /** Day ID */
  dayId: number;
  /** Display top position */
  topF: number;
  /** Display top position (truncated to 1 decimal pt) */
  top: number;
  /** Y coordinate on recycler */
  y: number;
  /** Cumulative number of photos before this tick */
  count: number;
  /** Is a new month */
  isMonth: boolean;
  /** Text if any (e.g. year) */
  text?: string | number;
  /** Whether this tick should be shown */
  s?: boolean;
  /** Key for vue component */
  key?: number;
};

export type TopMatter = {
  type: TopMatterType;
};
export enum TopMatterType {
  NONE = 0,
  FOLDER = 1,
  TAG = 2,
  FACE = 3,
  ALBUM = 4,
}
export type TopMatterFolder = TopMatter & {
  type: TopMatterType.FOLDER;
  list: {
    text: string;
    path: string;
  }[];
};

export type ISelectionAction = {
  /** Display text */
  name: string;
  /** Icon component */
  icon: VueConstructor;
  /** Action to perform */
  callback: (selection: Map<number, IPhoto>) => Promise<void>;
  /** Condition to check for including */
  if?: (self?: any) => boolean;
  /** Allow for public routes (default false) */
  allowPublic?: boolean;
};
