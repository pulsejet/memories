export type IFileInfo = {
    /** Database file ID */
    fileid: number;
    /** Full file name, e.g. /pi/test/Qx0dq7dvEXA.jpg */
    filename: string;
    /** Base name of file e.g. Qx0dq7dvEXA.jpg */
    basename: string;
    /** Etag identifier */
    etag: string;
    /** File has preview available */
    hasPreview: boolean;
    /** File is marked favorite */
    favorite: boolean;
    /** Vue flags */
    flag?: number;
}

export type IDay = {
    /** Day ID */
    dayid: number;
    /** Number of photos in this day */
    count: number;
    /** Set of rows in the day */
    rows?: Set<IRow>;
    /** List of photos for this day */
    detail?: IPhoto[];
    /** WebDAV fileInfos, fetched before viewer open */
    fileInfos?: IFileInfo[];
    /** Original fileIds from fileInfos */
    origFileIds?: Set<number>;
}

export type IPhoto = {
    /** Nextcloud ID of file */
    fileid: number;
    /** Etag from server */
    etag?: string;
    /** Bit flags */
    flag: number;
    /** DayID from server */
    dayid?: number;
    /** Reference to day object */
    d?: IDay;
    /** Video flag from server */
    isvideo?: boolean;
    /** Favorite flag from server */
    isfavorite?: boolean;
    /** Is this a folder */
    isfolder?: boolean;
    /** Is this a tag */
    istag?: boolean;
    /** Is this a face */
    isface?: boolean;
    /** Optional datetaken epoch */
    datetaken?: number;
}

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
    /** ID of face if this is a face */
    faceid?: number;
}

export type IRow = {
    /** Vue Recycler identifier */
    id?: number;
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
}
export type IHeadRow = IRow & {
    type: IRowType.HEAD;
    selected: boolean;
}
export enum IRowType {
    HEAD = 0,
    PHOTOS = 1,
    FOLDERS = 2,
}

export type ITick = {
    /** Day ID */
    dayId: number;
    /** Number of ROWS above this (dynamic) */
    top: number;
    /** Extra static distance from top (for headers) */
    topS: number;
    /** Actual Y position calculated (C) */
    topC: number;
    /** Text if any (e.g. year) */
    text?: string | number;
    /** Whether this tick should be shown */
    s?: boolean;
}

export type TopMatter = {
    type: TopMatterType;
}
export enum TopMatterType {
    NONE = 0,
    FOLDER = 1,
}
export type TopMatterFolder = TopMatter & {
    type: TopMatterType.FOLDER;
    list: {
        text: string;
        path: string;
    }[];
}
