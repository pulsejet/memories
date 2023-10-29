import { nativex } from './api';
import { addOrigin } from './basic';
import type { IPhoto } from '@types';

/**
 * Play a video from the given URL.
 * @param photo Photo to play
 * @param urls URLs to play (remote)
 */
export async function playVideo(photo: IPhoto, urls: string[]) {
  nativex?.playVideo?.(photo.auid ?? String(), photo.fileid, JSON.stringify(urls.map(addOrigin)));
}

/**
 * Destroy the video player.
 */
export async function destroyVideo(photo: IPhoto) {
  nativex?.destroyVideo?.(photo.fileid);
}
