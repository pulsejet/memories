import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus';
import type { FragmentName, Fragment } from './fragment';
import type { IConfig, IPhoto } from '@typings';

export type BusEvent = {
  /** Open/close the navigation drawer */
  'toggle-navigation': { open: boolean };
  /** File was created */
  'files:file:created': { fileid: number };
  /** File was updated */
  'files:file:updated': { fileid: number };

  /** Native sidebar was opened */
  'files:sidebar:opened': null;
  /** Native sidebar was closed */
  'files:sidebar:closed': null;
  /** Final event after sidebar is opened */
  'memories:sidebar:opened': null;
  /** Final event after sidebar is closed */
  'memories:sidebar:closed': null;

  /** Window was resized */
  'memories:window:resize': null;
  /** User configuration was changed */
  'memories:user-config-changed': {
    setting: keyof IConfig;
    value: IConfig[keyof IConfig];
  } | null;

  /** Filters have been updated */
  'memories:filters:changed': {
    minRating: number;
    tags: string[];
  };

  /**
   * Remove these photos from the timeline.
   * Each photo object is required to have the `d` (day) property.
   */
  'memories:timeline:deleted': IPhoto[];
  /** Viewer has requested fetching day */
  'memories:timeline:fetch-day': number;
  /** Soft-refresh the timeline */
  'memories:timeline:soft-refresh': null;
  /** Hard-refresh the timeline */
  'memories:timeline:hard-refresh': null;
  /** Timeline recycler scrolling */
  'memories.recycler.scroll': {
    current: number;
    previous: number;
    dynTopMatterVisible: boolean;
  };

  /** Albums were updated for these photos */
  'memories:albums:update': IPhoto[];

  /** NativeX database was updated */
  'nativex:db:updated': null;
} & {
  /** A fragment was removed from the route */
  [key in `memories:fragment:pop:${FragmentName}`]: Fragment;
};

/**
 * Wrapper around Nextcloud's event bus.
 */
export const bus = {
  /**
   * Emit an event on the Nextcloud event bus.
   * @param name Name of event
   * @param data arguments
   */
  emit<T extends keyof BusEvent>(name: T, data: BusEvent[T]): void {
    // @ts-expect-error - legacy
    emit(name, data);
  },

  /**
   * Subscribe to an event on the Nextcloud event bus.
   * @param name Name of event
   * @param callback Callback to be called when the event is emitted
   */
  on<T extends keyof BusEvent>(name: T, callback: (data: BusEvent[T]) => void): void {
    subscribe(name, callback);
  },

  /**
   * Unsubscribe from an event on the Nextcloud event bus.
   * @param name Name of event
   * @param callback Same callback that was passed to `on`
   */
  off<T extends keyof BusEvent>(name: T, callback: (data: BusEvent[T]) => void): void {
    unsubscribe(name, callback);
  },
};
