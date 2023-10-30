import { NAPI, nativex } from './api';
import { has } from './basic';

import { API } from '@services/API';
import * as utils from '@services/utils';

import type { IDay, IPhoto } from '@typings';

/** Memcache for <dayId, Photos> */
const daysCache = new Map<number, IPhoto[]>();

/** Set of all seen AUIDs and BUIDs */
const seenABUIDs = new Set<string>();

// Clear the cache whenever the timeline is refreshed
if (has()) {
  document.addEventListener('DOMContentLoaded', () => {
    utils.bus.on('nativex:db:updated', () => daysCache.clear());
  });
}

/**
 * Merge incoming days into current days.
 * Both arrays MUST be sorted by dayid descending.
 * @param current Response to update
 * @param incoming Incoming response
 * @return merged days
 */
export function mergeDays(current: IDay[], incoming: IDay[]): IDay[] {
  // Do a two pointer merge keeping the days sorted in O(n) time
  // If a day is missing from current, add it
  // If a day already exists in current, update haslocal on it
  let i = 0;
  let j = 0;

  // Merge local photos into remote photos
  const merged: IDay[] = [];
  while (i < current.length && j < incoming.length) {
    const curr = current[i];
    const inc = incoming[j];
    if (curr.dayid === inc.dayid) {
      curr.haslocal ||= inc.haslocal;
      merged.push(curr);
      i++;
      j++;
    } else if (curr.dayid > inc.dayid) {
      merged.push(curr);
      i++;
    } else {
      merged.push(inc);
      j++;
    }
  }

  // Add remaining current days
  while (i < current.length) {
    merged.push(current[i]);
    i++;
  }

  // Add remaining incoming days
  while (j < incoming.length) {
    merged.push(incoming[j]);
    j++;
  }

  return merged;
}

/**
 * Merge incoming photos into current photos.
 * @param current Response to update
 * @param incoming Incoming response
 */
export function mergeDay(current: IPhoto[], incoming: IPhoto[]): void {
  // Create sets of current AUIDs and BUIDs
  const auids = new Set<string>();
  const buids = new Set<string>();
  for (const photo of current) {
    if (photo.auid) auids.add(photo.auid);
    if (photo.buid) buids.add(photo.buid);
  }

  // Filter out files that are only available locally
  for (const photo of incoming) {
    if (!auids.has(photo.auid!) && !buids.has(photo.buid!)) {
      current.push(photo);
    }
  }

  // Sort by epoch value
  current.sort((a, b) => (b.epoch ?? 0) - (a.epoch ?? 0));
}

/**
 * Run internal hooks on fresh day received from server
 * Does not update the passed objects in any way
 * @param current Photos from day response
 */
export function processFreshServerDay(this: any, dayId: number, photos: IPhoto[]): void {
  const auids: Set<string> = (this.pfsdaq ??= new Set<string>());
  const buids: Set<string> = (this.pfsdbq ??= new Set<string>());

  // Add to queue
  for (const photo of photos) {
    if (photo.auid) auids.add(photo.auid);
    if (photo.buid) buids.add(photo.buid);
  }

  // Debounce
  utils.setRenewingTimeout(
    this,
    'pfsdq_timer',
    () => {
      const auidsa: string[] = [],
        buidsa: string[] = [];

      // Only keep the seen AUIDs and BUIDs
      for (const auid of auids) {
        if (seenABUIDs.has(auid)) {
          auidsa.push(auid);
          seenABUIDs.delete(auid);
        }
      }
      for (const buid of buids) {
        if (seenABUIDs.has(buid)) {
          buidsa.push(buid);
          seenABUIDs.delete(buid);
        }
      }

      // Nothing to do?
      if (auidsa.length || buidsa.length) {
        nativex.setHasRemote(JSON.stringify(auidsa), JSON.stringify(buidsa), true);
      }

      // Done
      auids.clear();
      buids.clear();
    },
    1000,
  );
}

/**
 * Get the local days response
 */
export async function getLocalDays(): Promise<IDay[]> {
  if (!has()) return [];

  const res = await fetch(NAPI.DAYS());
  if (!res.ok) return [];

  const days: IDay[] = await res.json();
  days.forEach((d) => (d.haslocal = true));

  return days;
}

/**
 * Fetches the local photos from the native interface
 * @param dayId Day ID to get local photos for
 * @returns
 */
export async function getLocalDay(dayId: number): Promise<IPhoto[]> {
  if (!has()) return [];

  // Check cache
  if (daysCache.has(dayId)) return daysCache.get(dayId)!;

  const res = await fetch(NAPI.DAY(dayId));
  if (!res.ok) return [];

  const photos: IPhoto[] = await res.json();
  photos.forEach((p) => {
    if (p.auid) seenABUIDs.add(p.auid);
    if (p.buid) seenABUIDs.add(p.buid);
    p.islocal = true;
  });

  // Cache the response
  daysCache.set(dayId, photos);

  return photos;
}

/**
 * Request deletion of local photos wherever available.
 * @param photos List of photos to delete
 * @returns The number of photos for which confirmation was received
 * @throws If the request fails
 */
export async function deleteLocalPhotos(photos: IPhoto[], dry: boolean = false): Promise<number> {
  if (!has()) return 0;

  const auids = photos.map((p) => p.auid).filter((a) => !!a) as string[];

  // Delete local photos
  const res = await fetch(API.Q(NAPI.IMAGE_DELETE(auids), { dry }));
  if (!res.ok) throw new Error('Failed to delete photos');

  const data = await res.json();
  return data.confirms ? data.count : 0;
}
