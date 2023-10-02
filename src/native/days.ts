import axios from '@nextcloud/axios';
import { NAPI } from './api';
import { API } from '../services/API';
import { has } from './basic';
import type { IDay, IPhoto } from '../types';

/**
 * Extend a list of days with local days.
 * Fetches the local days from the native interface.
 */
export async function extendDaysWithLocal(days: IDay[]) {
  if (!has()) return;

  // Query native part
  const res = await fetch(NAPI.DAYS());
  if (!res.ok) return;
  const local: IDay[] = await res.json();
  const remoteMap = new Map(days.map((d) => [d.dayid, d]));

  // Merge local days into remote days
  for (const day of local) {
    const remote = remoteMap.get(day.dayid);
    if (remote) {
      remote.count = Math.max(remote.count, day.count);
    } else {
      days.push(day);
    }
  }

  // TODO: sort depends on view
  // (but we show it for only timeline anyway for now)
  days.sort((a, b) => b.dayid - a.dayid);
}

/**
 * Extend a list of photos with local photos.
 * Fetches the local photos from the native interface and filters out duplicates.
 *
 * @param dayId Day ID to append local photos to
 * @param photos List of photos to append to (duplicates will not be added)
 * @returns
 */
export async function extendDayWithLocal(dayId: number, photos: IPhoto[]) {
  if (!has()) return;

  // Query native part
  const res = await fetch(NAPI.DAY(dayId));
  if (!res.ok) return;

  // Merge local photos into remote photos
  const localPhotos: IPhoto[] = await res.json();
  const serverAUIDs = new Set(photos.map((p) => p.auid));

  // Filter out files that are only available locally
  const localOnly = localPhotos.filter((p) => !serverAUIDs.has(p.auid));
  localOnly.forEach((p) => (p.islocal = true));
  photos.push(...localOnly);

  // Sort by epoch value
  photos.sort((a, b) => (b.epoch ?? 0) - (a.epoch ?? 0));
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
  const res = await axios.get(API.Q(NAPI.IMAGE_DELETE(auids), { dry }));
  return res.data.confirms ? res.data.count : 0;
}
