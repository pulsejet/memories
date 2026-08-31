import type { APIRequestContext } from '@playwright/test';
import type { IImageInfo, IPhoto } from '@typings';
import { appUrl, authHeaders } from './login';

// Cleanup unpredictable values from photo object.
export function cleanupPhoto(item: IPhoto): void {
  if (typeof item.etag !== 'string' || item.etag.length === 0) {
    throw new Error(`Invalid etag: ${item.etag}`);
  }
  if (typeof item.fileid !== 'number' || item.fileid <= 0) {
    throw new Error(`Invalid fileid: ${item.fileid}`);
  }

  delete item.etag;
  item.fileid = 0;
  item.flag = 0;
}

// Get image info by fileid from the image info endpoint.
export async function getImageInfo(request: APIRequestContext, fileid: number): Promise<IImageInfo> {
  const res = await request.get(`${appUrl}/api/image/info/${fileid}`, { headers: authHeaders });
  if (!res.ok()) throw new Error(`getImageInfo failed: ${res.status()}`);
  return res.json();
}
