import { loadState } from '@nextcloud/initial-state';
import type { IPhoto } from '@typings';

/** Global constants */
export const constants = Object.freeze({
  FLAG_PLACEHOLDER: 1 << 0,
  FLAG_LOAD_FAIL: 1 << 1,
  FLAG_IS_VIDEO: 1 << 2,
  FLAG_IS_FAVORITE: 1 << 3,
  FLAG_SELECTED: 1 << 4,
  FLAG_LEAVING: 1 << 5,
  FLAG_IS_LOCAL: 1 << 6,

  FACE_NULL: 'NULL',
  PLACES_NULL: 'NULL',

  MIME_RAW: 'image/x-dcraw',
  FORBIDDEN_EDIT_MIMES: ['image/bmp', 'image/x-dcraw', 'video/MP2T'], // Exif.php

  ALBUM_SORT_FLAGS: {
    DESCENDING: 1 << 0, // default true
    LAST_UPDATE: 1 << 1, // default
    CREATED: 1 << 2,
    NAME: 1 << 3,
    OLDEST: 1 << 4, // sort by oldest photo in album
    NEWEST: 1 << 5, // ditto for newest
  },
});

/**
 * Initial state pulled from Nextcloud's HTML page
 */
export const initstate = Object.freeze({
  noDownload: loadState('memories', 'no_download', false) !== false,
  shareTitle: loadState('memories', 'share_title', '') as string,
  shareType: loadState('memories', 'share_type', null) as 'file' | 'folder' | 'album' | null,
  singleItem: loadState('memories', 'single_item', null) as IPhoto | null,
  allow_upload: loadState('memories', 'allow_upload', false) as boolean,
  allow_delete: loadState('memories', 'allow_delete', false) as boolean,
});

/**
 * Convert server-side flags to bitmask
 * @param photo Photo to process
 */
export function convertFlags(photo: IPhoto) {
  if (typeof photo.flag === 'undefined') {
    photo.flag = 0; // flags
    photo.imageInfo = null; // make it reactive
  }

  if (photo.isvideo) {
    photo.flag |= constants.FLAG_IS_VIDEO;
    delete photo.isvideo;
  }
  if (photo.isfavorite) {
    photo.flag |= constants.FLAG_IS_FAVORITE;
    delete photo.isfavorite;
  }
  if (photo.islocal) {
    photo.flag |= constants.FLAG_IS_LOCAL;
    delete photo.islocal;
  }
}

/**
 * Copy over server flags from one photo object to another.
 * @param src Source photo
 * @param dst Destination photo
 */
export function copyPhotoFlags(src: IPhoto, dst: IPhoto) {
  // copy a single flag
  const copy = (flag: number) => (dst.flag = src.flag & flag ? dst.flag | flag : dst.flag & ~flag);

  // copy all flags
  copy(constants.FLAG_IS_VIDEO);
  copy(constants.FLAG_IS_FAVORITE);
  copy(constants.FLAG_IS_LOCAL);
}
