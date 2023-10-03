import { NAPI, nativex } from './api';
import { API } from '../services/API';
import { has } from './basic';
import * as utils from '../services/utils';
import type { IDay, IPhoto } from '../types';

/** Memcache for <dayId, Photos> */
const daysCache = new Map<number, IPhoto[]>();

// Clear the cache whenever the timeline is refreshed
if (has()) {
  document.addEventListener('DOMContentLoaded', () => {
    utils.bus.on('memories:timeline:soft-refresh', () => daysCache.clear());
    utils.bus.on('memories:timeline:hard-refresh', () => daysCache.clear());
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
 * @returns added photos
 */
export function mergeDay(current: IPhoto[], incoming: IPhoto[]): IPhoto[] {
  // Merge local photos into remote photos
  const currentAUIDs = new Map<number, IPhoto>();
  for (const photo of current) {
    currentAUIDs.set(photo.auid!, photo);
  }

  // Filter out files that are only available locally
  const added: IPhoto[] = [];
  for (const photo of incoming) {
    const serverPhoto = currentAUIDs.get(photo.auid!);
    if (serverPhoto) {
      nativex.setServerId(photo.auid!, serverPhoto.fileid);
    }

    current.push(photo);
    added.push(photo);
  }

  // Sort by epoch value
  current.sort((a, b) => (b.epoch ?? 0) - (a.epoch ?? 0));

  return added;
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
  photos.forEach((p) => (p.islocal = true));

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

  const auids = photos.map((p) => p.auid).filter((a) => !!a) as number[];

  // Delete local photos
  const res = await fetch(API.Q(NAPI.IMAGE_DELETE(auids), { dry }));
  if (!res.ok) throw new Error('Failed to delete photos');

  const data = await res.json();
  return data.confirms ? data.count : 0;
}
