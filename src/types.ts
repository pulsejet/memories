export type IFileInfo = {
    fileid: number;
    filename: string;
    etag: string;
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
    /** Reference to day object */
    d?: IDay;
    /** Video flag from server */
    isvideo?: boolean;
    /** Favorite flag from server */
    isfavorite?: boolean;
}

export type IRow = {
    /** Vue Recycler identifier */
    id?: number;
    /** Day ID */
    dayId: number;
    /** Refrence to day object */
    day: IDay;
    /** Whether this is a head row */
    head?: boolean;
    /** [Head only] Title of the header */
    name?: string;
    /** Main list of photo items */
    photos?: IPhoto[];
    /** Height in px of the row */
    size?: number;
    /** Count of placeholders to create */
    pct?: number;
}

export type ITick = {
    /** Day ID */
    dayId: number;
    /** Top row at this */
    top: number;
    /** Static distance from top (for headers) */
    topS: number;
    /** Count row distance from top (dynamic) */
    topC: number;
    /** Text if any (e.g. year) */
    text?: string | number;
    /** Whether this tick should be shown */
    s?: boolean;
}