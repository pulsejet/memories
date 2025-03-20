import axios from '@nextcloud/axios';

import { API } from '@services/API';
import type { ICluster, ITrip } from '@typings';

export async function getTrips(
  opts = {
    covers: 1,
  },
) {
  return (await axios.get<ICluster[]>(API.Q(API.TRIP_LIST(), opts ?? {}))).data;
}

export async function getTripInfo(id: string | number): Promise<ITrip> {
  return (await axios.get<ITrip>(API.TRIP_INFO(id))).data;
}

/**
 * Interface for trip slideshow media item
 */
export interface ITripMedia {
  id: number;
  type: 'image' | 'video';
  mime: string;
  etag: string;
  path: string;
  datetaken: string;
  w: number;
  h: number;
  duration: number | null;
  clipStart: number | null;
  clipDuration: number | null;
  previewUrl: string;
  downloadUrl: string;
  displayDuration: number | null;
}

/**
 * Get slideshow media for a trip
 * @param tripId Trip ID
 * @param maxVideoDuration Optional maximum duration for video clips in seconds
 * @returns Array of media items
 */
export async function getTripSlideshowMedia(
  tripId: number | string,
  maxVideoDuration?: number
): Promise<ITripMedia[]> {
  const params = maxVideoDuration !== undefined ? { max_video_duration: maxVideoDuration } : {};
  return (await axios.get<ITripMedia[]>(`/apps/memories/api/trip-slideshow/${tripId}`, { params })).data;
}
