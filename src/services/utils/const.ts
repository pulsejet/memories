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
  },
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
}
