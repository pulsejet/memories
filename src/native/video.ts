import { NAPI, nativex } from './api';
import { addOrigin, has } from './basic';
import staticConfig from '@services/static-config';
import { isLikelySamePhoto, isLocalPhoto } from '@services/utils/helpers';
import type { IPhoto } from '@typings';

/**
 * Play a video from the given URL.
 * @param photo Photo to play
 * @param urls URLs to play (remote)
 */
export async function playVideo(photo: IPhoto, urls: string[]) {
  const loop = staticConfig.getSync('video_loop') || false;
  if (typeof nativex?.playVideo2 === 'function') {
    nativex?.playVideo2?.(photo.auid ?? String(), photo.fileid, JSON.stringify(urls.map(addOrigin)), loop);
  } else {
    nativex?.playVideo?.(photo.auid ?? String(), photo.fileid, JSON.stringify(urls.map(addOrigin)));
  }
}

/**
 * Destroy the video player.
 */
export async function destroyVideo(photo: IPhoto) {
  nativex?.destroyVideo?.(photo.fileid);
}

/**
 * Local video URL for vidstack, if the photo should play from device.
 * Returns null for remote-only videos (use HLS / direct instead).
 */
export function getLocalVideoUrl(photo: IPhoto): string | null {
  if (!has() || !photo) {
    return null;
  } else if (isLocalPhoto(photo)) {
    return NAPI.VIDEO_FULL(photo.fileid);
  } else if (isLikelySamePhoto(photo, photo.local_photo)) {
    return NAPI.VIDEO_FULL(photo.local_photo.fileid);
  } else {
    return null;
  }
}
