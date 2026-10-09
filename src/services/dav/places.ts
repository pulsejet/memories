import axios from '@nextcloud/axios';

import { API } from '@services/API';

import type { ICluster } from '@typings';
import type { AbortOpts } from '@services/utils/abort';

export async function getPlaces({ covers = 1, signal }: { covers?: number } & AbortOpts = {}) {
  return (await axios.get<ICluster[]>(API.Q(API.PLACE_LIST(), { covers }), { signal })).data;
}
