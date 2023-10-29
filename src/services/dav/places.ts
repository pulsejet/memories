import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { ICluster } from '@types';

export async function getPlaces() {
  return (await axios.get<ICluster[]>(API.PLACE_LIST())).data;
}
