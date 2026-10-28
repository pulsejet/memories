import { NAPI, has } from './api';
import { isLikelySamePhoto, isLocalPhoto } from '@services/utils/photo';
import type { IPhoto } from '@typings';

/**
 * Local video URL for the native player, if the photo should play from device.
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
