import { NAPI, nativex } from './api';
import { API } from '../services/API';
import { has } from './basic';
import type { IDay, IPhoto } from '../types';

/**
 * Merge incoming days into current days.
 * @param current Response to update
 * @param incoming Incoming response
 * @return touched or added days
 */
export function mergeDays(current: IDay[], incoming: IDay[]) {
  const currentMap = new Map(current.map((d) => [d.dayid, d]));

  for (const day of incoming) {
    const curr = currentMap.get(day.dayid);
    if (curr) {
      curr.count = Math.max(curr.count, day.count);

      // Copy over some flags
      curr.haslocal ||= day.haslocal;
    } else {
      current.push(day);
    }
  }

  // TODO: sort depends on view
  // (but we use this for only timeline anyway for now)
  current.sort((a, b) => b.dayid - a.dayid);
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

  const res = await fetch(NAPI.DAY(dayId));
  if (!res.ok) return [];

  const photos: IPhoto[] = await res.json();
  photos.forEach((p) => (p.islocal = true));

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
