import { nativex } from './api';
import { addOrigin } from './basic';
import staticConfig from '@services/static-config';
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
