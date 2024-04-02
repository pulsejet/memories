import { showError } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';

import { getAlbumFileInfos } from './albums';
import client, { remotePath } from './client';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';
import * as nativex from '@native';

import type { IFileInfo, IImageInfo, IPhoto } from '@typings';
import type { ResponseDataDetailed, SearchResult } from 'webdav';

const GET_FILE_CHUNK_SIZE = 50;

type GetFilesOpts = {
  /** Attempt to use some cached value to get filename (default true) */
  cache?: boolean;
  /** Get original route-independent filename for current user only (default false) */
  ignoreRoute?: boolean;
};

/**
 * Get file infos for list of files given Ids
 * @param photos list of photos
 * @param opts options
 * @details This tries to use the cached filename in the photo object (imageInfo.filename)
 * If none was found, then it will fetch the file info from the server.
 */
export async function getFiles(photos: IPhoto[], opts?: GetFilesOpts): Promise<IFileInfo[]> {
  // Some routes may have special handling of filenames
  if (!opts?.ignoreRoute) {
    if (_m.route.name === _m.routes.Albums.name) {
      return getAlbumFileInfos(photos, _m.route.params.user, _m.route.params.name);
    }
  }

  // Remove any local photos
  photos = photos.filter((photo) => !utils.isLocalPhoto(photo));

  // Cache and uncached photos
  const cache: IFileInfo[] = [];
  const rest: IPhoto[] = [];

  // Partition photos with and without cache
  if (utils.uid && opts?.cache !== false) {
    for (const photo of photos) {
      const filename = photo.imageInfo?.filename;
      if (filename) {
        cache.push({
          id: photo.fileid,
          fileid: photo.fileid,
          basename: photo.basename ?? filename.split('/').pop() ?? '',
          originalFilename: `/files/${utils.uid}${filename}`,
          filename: filename,
        });
      } else {
        rest.push(photo);
      }
    }
  }

  // Get file infos for the rest
  return cache.concat(await getFilesInternal1(rest));
}

async function getFilesInternal1(photos: IPhoto[]): Promise<IFileInfo[]> {
  // Get file IDs array
  const fileIds = photos.map((photo) => photo.fileid);

  // Divide fileIds into chunks of GET_FILE_CHUNK_SIZE
  const chunks: number[][] = [];
  for (let i = 0; i < fileIds.length; i += GET_FILE_CHUNK_SIZE) {
    chunks.push(fileIds.slice(i, i + GET_FILE_CHUNK_SIZE));
  }

  // Get file infos for each chunk
  return (await Promise.all(chunks.map(getFilesInternal2))).flat();
}

async function getFilesInternal2(fileIds: number[]): Promise<IFileInfo[]> {
  const prefixPath = `/files/${utils.uid}`;

  // IMPORTANT: if this isn't there, then a blank
  // returns EVERYTHING on the server!
  if (fileIds.length === 0) {
    return [];
  }

  // https://jsonformatter.org/xml-formatter
  const filter = fileIds
    .map((fileId) => `<d:eq><d:prop><oc:fileid/></d:prop><d:literal>${fileId}</d:literal></d:eq>`)
    .join('');

  // Make Search request
  const xml = `<?xml version="1.0" encoding="UTF-8"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns" xmlns:ns="https://github.com/icewind1991/SearchDAV/ns" xmlns:ocs="http://open-collaboration-services.org/ns"><d:basicsearch><d:select><d:prop><oc:fileid /></d:prop></d:select><d:from><d:scope><d:href>${prefixPath}</d:href><d:depth>0</d:depth></d:scope></d:from><d:where><d:or>${filter}</d:or></d:where></d:basicsearch></d:searchrequest>`;
  const response = (await client.search('', { data: xml, details: true })) as ResponseDataDetailed<SearchResult>;

  return response.data.results
    .filter((file) => file.props?.fileid)
    .map((file) => {
      // remote remotePath from start
      if (file.filename.startsWith(remotePath)) {
        file.filename = file.filename.substring(remotePath.length);
      }

      // create IFileInfo
      return {
        id: file.props!.fileid as number,
        fileid: file.props!.fileid as number,
        basename: file.basename,
        originalFilename: file.filename,
        filename: file.filename.replace(prefixPath, ''),
      };
    });
}

/**
 * Run promises in parallel, but only n at a time
 * @param promises Array of promise generator funnction (async functions)
 * @param n Number of promises to run in parallel
 * @details Each promise returned MUST resolve and not throw an error
 * @returns Generator of lists of results. Each list is of length n.
 */
export async function* runInParallel<T>(promises: (() => Promise<T>)[], n: number) {
  if (!promises.length) return;

  promises.reverse(); // reverse so we can use pop() efficiently

  const results: T[] = [];
  const running: Promise<void>[] = [];

  while (true) {
    // add one promise per iteration
    if (promises.length) {
      let task!: Promise<void>;
      running.push(
        (task = (async () => {
          // run the promise
          results.push(await promises.pop()!());

          // remove the promise from the running list
          running.splice(running.indexOf(task), 1);
        })()),
      );
    }

    // wait for one of the promises to finish
    if (running.length >= n || !promises.length) {
      await Promise.race(running);
    }

    // yield the results if the threshold is reached
    if (results.length >= n || !running.length) {
      yield results.splice(0, results.length);
    }

    // stop when all promises are done
    if (!running.length && !promises.length) break;
  }
}

/**
 * Extend given list of Ids with extra files for
 *
 * 1. Live Photos.
 * 2. Stacked RAW files.
 *
 * @param photos list of photos to search for
 *
 * @returns list of file ids that contains extra file Ids
 */
export async function extendWithStack(photos: IPhoto[]) {
  // Add Live Photos files
  const livePhotos: IPhoto[] = [];
  for await (const res of runInParallel(
    photos
      .filter((p) => p.liveid && !p.liveid.startsWith('self__'))
      .map((p) => async () => {
        try {
          const base = utils.getLivePhotoVideoUrl(p, false);
          const url = API.Q(base, { format: 'json' });
          const res = await axios.get<IPhoto>(url);
          return res.data;
        } catch (error) {
          console.error(error);
          return null;
        }
      }),
    10,
  )) {
    livePhotos.push(...res.filter(utils.truthy));
  }

  // Add stacked RAW files (deduped)
  const stackRaw = photos.map((p) => p.stackraw ?? []).flat();

  // Combine all files
  const combined = photos.concat(livePhotos, stackRaw);

  // De-duplicate keeping the order same as before
  // https://github.com/pulsejet/memories/issues/1056
  const cIds = new Set(combined.map((p) => p.fileid));
  return combined.filter((p) => cIds.delete(p.fileid));
}

/**
 * Delete all files in a given list of Ids
 *
 * @param photos list of photos to delete
 * @param confirm whether to show a confirmation dialog (default true)
 * @returns list of file ids that were deleted
 */
export async function* deletePhotos(photos: IPhoto[], confirm: boolean = true) {
  if (photos.length === 0) return;

  // Extend with stack unless this is an album
  const routeIsAlbums = _m.route.name === _m.routes.Albums.name;
  if (!routeIsAlbums) {
    photos = await extendWithStack(photos);
  }

  // Get set of unique file ids
  let fileIds = new Set(photos.map((p) => p.fileid));

  // Get files data. The double filter ensures we never delete something accidentally.
  const fileInfos = (await getFiles(photos)).filter((f) => fileIds.has(f.fileid));

  // Take intersection of fileIds and fileInfos
  fileIds = new Set(fileInfos.map((f) => f.fileid));

  // Check for locally available files and delete them.
  // For albums, we are not actually deleting.
  const hasNative = nativex.has() && !routeIsAlbums;

  // Check if native confirmation is available
  if (hasNative) {
    confirm &&= (await nativex.deleteLocalPhotos(photos, true)) !== photos.length;
  }

  // Show confirmation dialog if required
  if (confirm) {
    if (routeIsAlbums) {
      if (!(await utils.dialogs.removeFromAlbum(photos.length))) {
        throw new Error('User cancelled removal');
      }
    } else {
      if (!(await utils.dialogs.moveToTrash(photos.length))) {
        throw new Error('User cancelled deletion');
      }
    }
  }

  // Delete local files.
  if (hasNative) {
    // Delete local files.
    await nativex.deleteLocalPhotos(photos);

    // Remove purely local files
    const deleted = photos.filter(utils.isLocalPhoto);

    // Yield for the fully local files
    if (deleted.length > 0) {
      yield deleted.map((f) => f.fileid);
    }
  }

  // Delete each file
  const calls = fileInfos.map((fileInfo) => async () => {
    try {
      await client.deleteFile(fileInfo.originalFilename);
      return fileInfo.fileid;
    } catch (error) {
      console.error('Failed to delete', fileInfo, error);
      showError(
        t('memories', 'Failed to delete {fileName}.', {
          fileName: fileInfo.filename,
        }),
      );
      return 0;
    }
  });

  yield* runInParallel(calls, 10);
}

/**
 * Move all files in a given list of Ids to given destination
 *
 * @param photos list of photos to move
 * @param destination to move photos into
 * @param overwrite behaviour if the target exists. `true` overwrites, `false` fails.
 * @returns list of file ids that were moved
 */
export async function* movePhotos(photos: IPhoto[], destination: string, overwrite: boolean) {
  if (photos.length === 0) {
    return;
  }

  // Set absolute target path
  const prefixPath = `files/${utils.uid}`;
  let targetPath = prefixPath + destination;
  if (!targetPath.endsWith('/')) {
    targetPath += '/';
  }

  // Also move the stack files
  photos = await extendWithStack(photos);
  const fileIdsSet = new Set(photos.map((p) => p.fileid));

  // Get files data
  let fileInfos: IFileInfo[] = [];
  try {
    fileInfos = await getFiles(photos);
  } catch (e) {
    console.error('Failed to get file info for files to move', photos, e);
    showError(t('memories', 'Failed to move files.'));
    return;
  }

  // Move each file
  fileInfos = fileInfos.filter((f) => fileIdsSet.has(f.fileid));
  const calls = fileInfos.map((fileInfo) => async () => {
    try {
      await client.moveFile(
        fileInfo.originalFilename,
        targetPath + fileInfo.basename,
        // @ts-ignore - https://github.com/perry-mitchell/webdav-client/issues/329
        { headers: { Overwrite: overwrite ? 'T' : 'F' } },
      );
      return fileInfo.fileid;
    } catch (error) {
      console.error('Failed to move', fileInfo, error);
      if (error.response?.status === 412) {
        // Precondition failed (only if `overwrite` flag set to false)
        showError(
          t('memories', 'Could not move {fileName}, target exists.', {
            fileName: fileInfo.filename,
          }),
        );
        return 0;
      }

      showError(
        t('memories', 'Failed to move {fileName}.', {
          fileName: fileInfo.filename,
        }),
      );
      return 0;
    }
  });

  yield* runInParallel(calls, 10);
}

/**
 * Fill the imageInfo attributes of the given photos
 *
 * @param photos list of photos to fill
 * @param query options
 * @param query.tags whether to include tags in the response
 * @param progress callback to report progress
 */
export async function fillImageInfo(photos: IPhoto[], query?: { tags?: number }, progress?: (count: number) => void) {
  // Filter out photos that are local only
  const remote = photos.filter((p) => !utils.isLocalPhoto(p));

  // Number of photos done
  let done = photos.length - remote.length;
  if (done > 0) progress?.(done);

  // Load metadata for all photos
  const calls = remote.map((p) => async () => {
    try {
      const url = API.Q(API.IMAGE_INFO(p.fileid), query ?? {});
      const res = await axios.get<IImageInfo>(url);
      p.datetaken = res.data.datetaken;
      p.imageInfo = res.data;
    } catch (error) {
      console.error('Failed to get image info', p, error);
      showError(t('memories', 'Failed to load image info: {name}', { name: p.basename ?? p.fileid }));
    } finally {
      done++;
      progress?.(done);
    }
  });

  for await (const _ of runInParallel(calls, 8)) {
    // nothing to do
  }
}
