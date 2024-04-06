declare module '@typings' {
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

  export interface TimelineState {
    list: IRow[];
    heads: Map<number, IHeadRow>;
  }

  /** Type of IRow (0 = head, 1 = photos) */
  export type IRowType = 0 | 1;

  export type IRow = {
    /** Vue Recycler identifier */
    id?: string;
    /** Row ID from head */
    num: number;
    /** Day ID */
    dayId: number;
    /** Refrence to day object */
    day: IDay;
    /** Type of row (0 = head, 1 = photos) */
    type: IRowType;
    /** Main list of photo items */
    photos?: IPhoto[];

    /** Height in px of the row */
    size: number;
    /** Count of placeholders to create */
    pct?: number;
    /** Don't remove dom element */
    virtualSticky?: boolean;
  };

  export type IHeadRow = IRow & {
    /** Type of row */
    type: 0;
    /** Title of the header */
    name?: string;
    /** Header is for a month instead of day */
    ismonth?: boolean;
    /**  Boolean if the entire day is selected */
    selected: boolean;
    /** Bigger header text */
    super?: string;
  };
}
