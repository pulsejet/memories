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

  export type IRowSavedState = {
    size: number;
    pct?: number;
  };

  /** Type of IRow (0 = head, 1 = photos) */
  export type IRowType = IRow['type'];

  type IRowBase = {
    /** Vue Recycler identifier */
    id: string;
    /** Row ID from head */
    num: number;
    /** Height in px of the row */
    size: number;
    /** Don't remove dom element */
    virtualSticky?: boolean;
  };

  export type IPhotoRow = IRowBase & {
    /** Type of row (1 = photos) */
    type: 1;
    /** Day ID */
    dayId: number;
    /** Reference to day object */
    day: IDay;
    /** Main list of photo items (reactive) */
    photos: IPhoto[];
    /** Count of placeholders to create */
    pct?: number;
  };

  export type IHeadRow = IRowBase & {
    /** Type of row (0 = head) */
    type: 0;
    /** Day ID */
    dayId: number;
    /** Reference to day object */
    day: IDay;
    /** Title of the header */
    name?: string;
    /** Header is for a month instead of day */
    ismonth?: boolean;
    /** Reactive state */
    state: {
      /**  Boolean if the entire day is selected */
      selected: boolean;
    };
    /** Bigger header text */
    super?: string;
  };

  /** A timeline row, discriminated by `type` so templates narrow with v-if */
  export type IRow = IHeadRow | IPhotoRow;

  /** Shared mutable recycler geometry. */
  export type IRecyclerState = {
    scrollTop: number;
    startOffset: number;
    scrollerHeight: number;
    wrapperHeight: number;
  };
}
