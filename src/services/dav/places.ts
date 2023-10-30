import axios from '@nextcloud/axios';

import { API } from '@services/API';
import type { ICluster } from '@typings';

export async function getPlaces() {
  return (await axios.get<ICluster[]>(API.PLACE_LIST())).data;
}
