import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus';
import { IConfig, IPhoto } from '../../types';

type BusEvent = {
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

  /** Delete these photos from the timeline */
  'memories:timeline:deleted': IPhoto[];
  /** Viewer has requested fetching day */
  'memories:viewer:fetch-day': number;
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
};

/**
 * Emit an event on the Nextcloud event bus.
 * @param name Name of event
 * @param data arguments
 */
export const bus = {
  emit<T extends keyof BusEvent>(name: T, data: BusEvent[T]): void {
    emit(name, data as any);
  },
  on<T extends keyof BusEvent>(name: T, callback: (data: BusEvent[T]) => void): void {
    subscribe(name, callback);
  },
  off<T extends keyof BusEvent>(name: T, callback: (data: BusEvent[T]) => void): void {
    unsubscribe(name, callback);
  },
};
