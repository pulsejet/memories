import axios from '@nextcloud/axios';
import { API } from '@services/API';
import * as utils from '@services/utils';

export async function getUserDisplayName(uid: string | null): Promise<string> {
  if (!uid) return '';

  // First look in cache
  const cacheUrl = API.UID_NAME(uid);
  const cache = await utils.getCachedData<string>(cacheUrl);
  if (cache) return cache;

  // Network request and update cache
  const { data } = await axios.get(API.Q(cacheUrl, { uid }));
  const name = data?.user_display ?? '';
  utils.cacheData(cacheUrl, name);

  return name;
}
