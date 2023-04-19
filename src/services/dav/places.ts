import { ICluster } from '../../types';
import { API } from '../API';
import axios from '@nextcloud/axios';

export async function getPlaces() {
  return (await axios.get<ICluster[]>(API.PLACE_LIST())).data;
}
