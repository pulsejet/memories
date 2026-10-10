import axios from '@nextcloud/axios';

import { convertFlags } from '@services/constants';
import { config } from '@services/user-config';
import { API } from '@services/API';

import type { IDay, IPhoto } from '@typings';
import type { AbortOpts } from '@services/utils/abort';

/**
 * Get original onThisDay response.
 */
export async function getOnThisDayRaw(opts?: AbortOpts) {
  const signal = opts?.signal;
  const dayIds: number[] = [];
  const now = new Date();
  const nowUTC = new Date(now.getTime() - now.getTimezoneOffset() * 60000);

  const dayRange = config.onthisday_day_range;

  // Populate dayIds
  for (let i = 1; i <= 120; i++) {
    // +- 3 days from this day
    for (let j = -dayRange; j <= dayRange; j++) {
      const d = new Date(nowUTC);
      d.setUTCFullYear(d.getUTCFullYear() - i);
      d.setUTCDate(d.getUTCDate() + j);
      const dayId = Math.floor(d.getTime() / 1000 / 86400);
      dayIds.push(dayId);
    }
  }

  const res = await axios.post<IPhoto[]>(API.DAYS(), { dayIds }, { signal });

  res.data.forEach(convertFlags);
  return res.data;
}

/**
 * Get the onThisDay data
 * Query for last 120 years; should be enough
 */
export async function getOnThisDayData(opts?: AbortOpts): Promise<IDay[]> {
  // Query for photos
  let data = await getOnThisDayRaw(opts);
  opts?.signal?.throwIfAborted();

  // Group photos by day
  const ans: IDay[] = [];
  let prevDayId = Number.MIN_SAFE_INTEGER;
  for (const photo of data) {
    if (!photo.dayid) continue;

    // This works because the response is sorted by date taken
    if (photo.dayid !== prevDayId) {
      ans.push({
        dayid: photo.dayid,
        count: 0,
        detail: [],
      });
      prevDayId = photo.dayid;
    }

    // Add to last day
    const day = ans.at(-1)!;
    day.detail!.push(photo);
    day.count++;
  }

  return ans;
}
