import type { DeepReadonly } from 'vue';

import { API } from '@services/API';
import { NAPI } from '@native/api';

import { isLikelySamePhoto, isLocalPhoto } from './photo';

import type { IConfig, IPhoto } from '@typings';

/** Preview generation options */
type PreviewOpts = {
  /** Photo object to create preview for */
  photo: IPhoto;
};
type PreviewOptsSize = PreviewOpts & {
  /**
   * Directly specify the size of the preview.
   * If you already know the size of the photo, use msize instead,
   * so that caching can be utilized best. A size of 256 is not allowed
   * here since the thumbnails are not pre-generated.
   */
  size: 512 | 1024 | 2048 | [number, number] | 'screen';
};
type PreviewOptsMsize = PreviewOpts & {
  /**
   * Size of minimum edge of the preview (recommended).
   * This can only be used if the photo object has width and height.
   */
  msize: 256 | 512 | 1024 | 2048;
};
type PreviewOptsSquare = PreviewOpts & {
  /**
   * Size of the square preview.
   * Note that these will still be cached and requested with XImg multipreview.
   */
  sqsize: 256 | 512 | 1024;
};

/**
 * Get preview URL from photo object
 *
 * @param opts Preview options
 */
export function getPreviewUrl(opts: PreviewOptsSize | PreviewOptsMsize | PreviewOptsSquare) {
  // Destructure does not work with union types
  let { photo, size, msize, sqsize } = opts as PreviewOptsSize & PreviewOptsMsize & PreviewOptsSquare;

  // Square size is just size
  const square = sqsize !== undefined;
  if (square) size = sqsize as any;

  // Screen-appropriate size
  if (size === 'screen') {
    const sw = Math.floor(screen.width * devicePixelRatio);
    const sh = Math.floor(screen.height * devicePixelRatio);
    const longEdge = Math.max(sw, sh);
    size = [sw, sh];

    // Use capped full image if NativeX is used
    if (isLocalPhoto(photo)) {
      return API.Q(NAPI.IMAGE_FULL(photo.auid!), { size: longEdge });
    } else if (photo.local_photo?.auid && isLikelySamePhoto(photo, photo.local_photo)) {
      return API.Q(NAPI.IMAGE_FULL(photo.local_photo.auid), { size: longEdge });
    }
  }

  // Base size conversion
  if (msize !== undefined) {
    if (photo.w && photo.h) {
      size = (Math.floor((msize * Math.max(photo.w, photo.h)) / Math.min(photo.w, photo.h)) - 1) as any;
    } else {
      console.warn('Photo has no width or height but using msize');
      size = msize === 256 ? 512 : msize;
    }
  }

  // Convert to array
  const [x, y] = typeof size === 'number' ? [size, size] : size!;
  const a = square ? '0' : '1';
  const c = photo.etag;

  // NativeX preview
  if (isLocalPhoto(photo)) {
    return API.Q(NAPI.IMAGE_PREVIEW(photo.fileid), { c, x, y });
  } else if (isLikelySamePhoto(photo, photo.local_photo)) {
    return API.Q(NAPI.IMAGE_PREVIEW(photo.local_photo.fileid), { c, x, y });
  }

  // Preview from server
  return API.Q(API.IMAGE_PREVIEW(photo.fileid), { c, x, y, a });
}

/**
 * Get the URL for the imageInfo of a photo, including tags/clusters params.
 *
 * @param photo Photo object or fileid (remote only)
 * @param config User config to derive tags/clusters params
 */
export function getImageInfoUrl(photo: IPhoto | number, config: DeepReadonly<IConfig>): string {
  const fileid = typeof photo === 'number' ? photo : photo.fileid;

  // Base URL for getting image info.
  let base: string;
  if (typeof photo === 'object' && isLocalPhoto(photo)) {
    base = NAPI.IMAGE_INFO(fileid);
  } else {
    base = API.IMAGE_INFO(fileid);
  }

  // Public share route should not show clusters.
  const routeName = _m.route?.name?.toString() ?? '';
  const isPublic = routeName.endsWith('-share');

  // Include clusters like people and albums.
  let clusters: string | undefined;
  if (!isPublic) {
    const parts = [
      config.albums_enabled ? 'albums' : null,
      config.recognize_enabled ? 'recognize' : null,
      config.facerecognition_enabled ? 'facerecognition' : null,
    ].filter((c) => c);
    clusters = parts.join(',') || undefined;
  }

  // Include tags for public and logged in.
  const tags = config.systemtags_enabled ? 1 : undefined;

  return API.Q(base, { tags, clusters });
}
