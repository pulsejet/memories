import { IPhoto } from '../../types';

/** Global constants */
export const constants = {
  c: {
    FLAG_PLACEHOLDER: 1 << 0,
    FLAG_LOAD_FAIL: 1 << 1,
    FLAG_IS_VIDEO: 1 << 2,
    FLAG_IS_FAVORITE: 1 << 3,
    FLAG_SELECTED: 1 << 4,
    FLAG_LEAVING: 1 << 5,
    FLAG_IS_LOCAL: 1 << 6,
  },

  FACE_NULL: 'NULL',
};

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
    photo.flag |= constants.c.FLAG_IS_VIDEO;
    delete photo.isvideo;
  }
  if (photo.isfavorite) {
    photo.flag |= constants.c.FLAG_IS_FAVORITE;
    delete photo.isfavorite;
  }
  if (photo.islocal) {
    photo.flag |= constants.c.FLAG_IS_LOCAL;
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
  copy(constants.c.FLAG_IS_VIDEO);
  copy(constants.c.FLAG_IS_FAVORITE);
  copy(constants.c.FLAG_IS_LOCAL);
}
