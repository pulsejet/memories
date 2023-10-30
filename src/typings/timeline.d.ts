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
    /** Whether this is a head row */
    type: IRowType;
    /** [Head only] Title of the header */
    name?: string;
    /** [Head only] Boolean if the entire day is selected */
    selected?: boolean;
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
    type: 0;
    selected: boolean;
    super?: string;
  };
}
