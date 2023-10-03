import { nativex } from './api';
import { addOrigin } from './basic';
import type { IPhoto } from '../types';

/**
 * Play a video from the given URL.
 * @param photo Photo to play
 * @param urls URLs to play (remote)
 */
export async function playVideo(photo: IPhoto, urls: string[]) {
  const auid = photo.auid ?? photo.fileid;
  nativex?.playVideo?.(auid.toString(), photo.fileid.toString(), JSON.stringify(urls.map(addOrigin)));
}

/**
 * Destroy the video player.
 */
export async function destroyVideo(photo: IPhoto) {
  nativex?.destroyVideo?.(photo.fileid.toString());
}
