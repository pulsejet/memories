import { getCurrentUser } from '@nextcloud/auth';

import { constants as c } from './const';

import { API } from '@services/API';
import { NAPI } from '@native';

import type { IImageInfo, IPhoto } from '@typings';

/**
 * Get the current user UID
 */
export const uid = String(getCurrentUser()?.uid || String()) || null;

/**
 * Check if the current user is an admin
 */
export const isAdmin = Boolean(getCurrentUser()?.isAdmin);

/**
 * Check if width <= 768px
 */
export function isMobile() {
  return _m.window.innerWidth <= 768;
}

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
   * here size the thumbnails are not pre-generated.
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
    size = [sw, sh];

    // Use capped full image if NativeX is used
    if (isLocalPhoto(photo)) {
      return API.Q(NAPI.IMAGE_FULL(photo.auid!), { size: Math.max(sw, sh) });
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
  }

  // Preview from server
  return API.Q(API.IMAGE_PREVIEW(photo.fileid), { c, x, y, a });
}

/**
 * Check if the object is a local photo
 * @param photo Photo object
 */
export function isLocalPhoto(photo: IPhoto): boolean {
  return Boolean(photo?.fileid) && Boolean((photo?.flag ?? 0) & c.FLAG_IS_LOCAL);
}

/**
 * Check if an object is a video
 * @param photo Photo object
 */
export function isVideo(photo: IPhoto): boolean {
  return !!photo?.mimetype?.startsWith('video/') || !!(photo.flag & c.FLAG_IS_VIDEO);
}

/**
 * Get the URL for the imageInfo of a photo
 *
 * @param photo Photo object or fileid (remote only)
 */
export function getImageInfoUrl(photo: IPhoto | number): string {
  const fileid = typeof photo === 'number' ? photo : photo.fileid;

  if (typeof photo === 'object' && isLocalPhoto(photo)) {
    return NAPI.IMAGE_INFO(fileid);
  }

  return API.IMAGE_INFO(fileid);
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
 * Get the path of the folder on folders route
 * This function does not check if this is the folder route
 */
export function getFolderRoutePath(basePath: string) {
  let path = (_m.route.params.path || '/') as string | string[];
  path = typeof path === 'string' ? path : path.join('/');
  path = basePath + '/' + path;
  path = path.replace(/\/\/+/, '/'); // Remove double slashes
  return path;
}

/**
 * Get URL to Live Photo video part
 */
export function getLivePhotoVideoUrl(p: IPhoto, transcode: boolean) {
  return API.Q(API.VIDEO_LIVEPHOTO(p.fileid), {
    etag: p.etag,
    liveid: p.liveid,
    transcode: transcode ? _m.video.clientIdPersistent : undefined,
  });
}

/**
 * Set up hooks to set classes on parent element for Live Photo
 * @param video Video element
 * @param parent State object to update (reactivity)
 */
export function setupLivePhotoHooks(video: HTMLVideoElement, state: { playing: boolean }) {
  const div = video.closest('.memories-livephoto') as HTMLDivElement;

  // Playing state
  video.addEventListener('playing', () => (state.playing = true));
  video.addEventListener('play', () => div.classList.add('playing'));
  video.addEventListener('canplay', () => div.classList.add('canplay'));

  // Ended or pausing state
  const ended = () => {
    state.playing = false;
    div.classList.remove('playing');
  };
  video.addEventListener('ended', ended);
  video.addEventListener('pause', ended);
}

/**
 * Remove the extension from a filename
 */
export function removeExtension(filename: string) {
  return filename.replace(/\.[^/.]+$/, '');
}

/**
 * Check if the provided Axios Error is a network error.
 */
export function isNetworkError(error: any) {
  return error?.code === 'ERR_NETWORK';
}

/**
 * Add event listener to DOMContentLoaded and fire
 * callback immediately if the event has already fired.
 */
export function onDOMLoaded(callback: () => void) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
  } else {
    setTimeout(callback, 0);
  }
}

/**
 * Extract rating from EXIF data
 * @param exif EXIF data object
 * @returns Rating as number (0-5) or 0 if not found
 */
export function getRatingFromExif(exif: any): number {
  const rating = exif?.Rating;
  return typeof rating === 'number' ? rating : 0;
}

/**
 * Extract tags from EXIF data
 * @param exif EXIF data object
 * @returns Array of tag arrays (hierarchical paths)
 */
export function getTagsFromExif(exif: any): string[][] {
  if (!exif) return [];
  
  const ensureArray = (v: string | string[] | undefined | null) => v ? (Array.isArray(v) ? v : [v]) : [];
  
  const allTags: string[][] = [];
  const tagSet = new Set<string>();
  
  // Helper to add tags if not already present (with normalization for deduplication)
  const addTags = (tags: string[][]) => {
    for (const tag of tags) {
      const tagPath = Array.isArray(tag) ? tag : [tag];
      // Normalize to '/' separator for deduplication key
      const normalizedKey = tagPath.join('/').toLowerCase();
      if (!tagSet.has(normalizedKey)) {
        tagSet.add(normalizedKey);
        allTags.push(tagPath);
      }
    }
  };
  
  // Extract from TagsList (split by '/')
  const tagsList = ensureArray(exif.TagsList).map((tag) => tag.split('/'));
  addTags(tagsList);
  
  // Extract from HierarchicalSubject (split by '|')
  const hierarchicalSubject = ensureArray(exif.HierarchicalSubject).map((tag) => tag.split('|'));
  addTags(hierarchicalSubject);
  
  // Extract from Keywords (as individual tags)
  const keywords = ensureArray(exif.Keywords).map((tag) => {
    // Keywords might contain paths with '/' or '|' separator
    return tag.includes('/') ? tag.split('/') : 
           tag.includes('|') ? tag.split('|') : [tag];
  });
  addTags(keywords);
  
  // Extract from Subject (as individual tags)
  const subject = ensureArray(exif.Subject).map((tag) => [tag]);
  addTags(subject);
  
  // Filter out tags that are components of hierarchical tags
  return filterComponentTags(allTags);
}

/**
 * Filter out tags that are components of hierarchical tags
 * For example, if we have "Country/Italy", don't also show "Country" or "Italy"
 */
function filterComponentTags(tags: string[][]): string[][] {
  if (tags.length === 0) return tags;
  
  // Step 1: Collect all tags into normalized collections
  const flatTags = new Set<string>(); // Single-part tags (no separator)
  const hierarchicalTags: string[][] = []; // Multi-part tags (length > 1)
  const hierarchicalTagParts = new Set<string>(); // All parts from hierarchical tags
  
  for (const tag of tags) {
    const normalized = tag.map(part => part.toLowerCase());
    
    if (normalized.length === 1) {
      // Single-part tag
      flatTags.add(normalized[0]);
    } else {
      // Multi-part hierarchical tag
      hierarchicalTags.push(tag);
      
      // Add all parts to hierarchicalTagParts
      for (const part of normalized) {
        hierarchicalTagParts.add(part);
      }
    }
  }
  
  // Step 2: Combination - remove flat tags that are parts of hierarchical tags
  const result: string[][] = [];
  
  // Add flat tags that are NOT components of hierarchical tags
  for (const flatTag of flatTags) {
    if (!hierarchicalTagParts.has(flatTag)) {
      // Find the original case from the input tags
      const originalTag = tags.find(t => t.length === 1 && t[0].toLowerCase() === flatTag);
      if (originalTag) {
        result.push(originalTag);
      }
    }
  }
  
  // Add all hierarchical tags
  result.push(...hierarchicalTags);
  
  return result;
}
