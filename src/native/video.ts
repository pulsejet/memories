import { NAPI, has } from './api';
import { isLikelySamePhoto, isLocalPhoto } from '@services/utils/photo';
import type { IPhoto } from '@typings';

/**
 * Local video URL for the native player, if the photo should play from device.
 * The native hole player reads the content URI directly; the web player needs the proxy.
 * Returns null for remote-only videos (use HLS / direct instead).
 */
export function getLocalVideoUrl(photo: IPhoto, nativeHole: boolean): string | null {
  if (!has() || !photo) {
    return null;
  } else if (isLocalPhoto(photo)) {
    return localVideoUrl(photo, nativeHole);
  } else if (isLikelySamePhoto(photo, photo.local_photo)) {
    return localVideoUrl(photo.local_photo, nativeHole);
  } else {
    return null;
  }
}

function localVideoUrl(photo: IPhoto, nativeHole: boolean): string {
  if (nativeHole && photo.local_uri) {
    return photo.local_uri;
  } else {
    return NAPI.VIDEO_FULL(photo.fileid);
  }
}
