import axios from '@nextcloud/axios';

import { API } from '@services/API';
import type { ICluster } from '@typings';

export async function getPlaces(
  opts = {
    covers: 1,
  },
) {
  return (await axios.get<ICluster[]>(API.Q(API.PLACE_LIST(), opts ?? {}))).data;
}
