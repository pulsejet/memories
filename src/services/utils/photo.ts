import { Md5 } from 'ts-md5';

import { constants } from '@services/constants';

import type { IImageInfo, IPhoto } from '@typings';

/**
 * Check if the object is a local photo
 * @param photo Photo object
 */
export function isLocalPhoto(photo: IPhoto): boolean {
  return Boolean(photo?.fileid) && Boolean((photo?.flag ?? 0) & constants.FLAG_IS_LOCAL);
}

/**
 * Check if an object is a video
 * @param photo Photo object
 */
export function isVideo(photo: IPhoto): boolean {
  return !!photo?.mimetype?.startsWith('video/') || !!(photo.flag & constants.FLAG_IS_VIDEO);
}

/**
 * Check if a photo object likely is the same as another.
 * Used to check local native vs remote photos for previews.
 */
export function isLikelySamePhoto(photoA: IPhoto, photoB?: IPhoto): photoB is IPhoto {
  return (
    !!photoA &&
    !!photoB &&
    photoA.w === photoB.w &&
    photoA.h === photoB.h &&
    photoA.size === photoB.size &&
    photoA.basename === photoB.basename &&
    photoA.buid === photoB.buid
  );
}

/**
 * Update photo object using imageInfo.
 */
export function updatePhotoFromImageInfo(photo: IPhoto, imageInfo: IImageInfo) {
  photo.etag = imageInfo.etag;
  photo.basename = imageInfo.basename;
  photo.mimetype = imageInfo.mimetype;
  photo.w = imageInfo.w;
  photo.h = imageInfo.h;
  photo.imageInfo = {
    ...photo.imageInfo,
    ...imageInfo,
  };
}

/**
 * Calculate the AUID of photos for dedup and native lookups.
 */
export function applyAuids(photos: IPhoto[] | null | undefined): void {
  for (const photo of photos ?? []) {
    if (!photo.auid && photo.epoch && photo.size) {
      photo.auid = Md5.hashStr(`${photo.epoch}${photo.size}`);
    }
  }
}
