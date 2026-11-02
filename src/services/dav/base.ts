import axios from '@nextcloud/axios';
import { showError } from '@services/utils/dialog';

import { getAlbumFileInfos } from './albums';
import client, { remotePath } from './client';
import * as xml from './xml';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { routeIs } from '@services/route-is';
import { routes } from '@services/router';
import { isAbortError } from '@services/utils/abort';
import * as utils from '@services/utils/common';
import * as nativex from '@native';

import type { RouteLocationNormalized } from 'vue-router';
import type { IFileInfo, IImageInfo, IPhoto } from '@typings';
import type { ResponseDataDetailed, SearchResult } from 'webdav';
import type { AbortOpts } from '@services/utils/abort';

const GET_FILE_CHUNK_SIZE = 50;
const GET_FILE_CONCURRENCY = 5;

type GetFilesOpts = {
  /** Get original route-independent filename for current user only (default false) */
  ignoreRoute?: boolean;
  /** Current route for album and public share handling (not needed with ignoreRoute) */
  route?: RouteLocationNormalized;
} & AbortOpts;

/**
 * Get file infos for list of files given Ids
 * @param photos list of photos
 * @param opts options
 * @details This uses the cached filename in the photo object (imageInfo.filename)
 * If none was found, then it will fetch the file info from the server.
 */
export async function getFiles(photos: IPhoto[], opts?: GetFilesOpts): Promise<IFileInfo[]> {
  // Some routes may have special handling of filenames
  if (!opts?.ignoreRoute && opts?.route) {
    if (opts.route.name === routes.AlbumsList.name || opts.route.name === routes.Albums.name) {
      return getAlbumFileInfos(photos, opts.route.params.user?.toString(), opts.route.params.name?.toString());
    }
  }

  // Remove any local photos
  photos = photos.filter((photo) => !utils.isLocalPhoto(photo));

  // For public shares, use files/{token} path, otherwise files/{uid}
  const token = opts?.route?.params.token?.toString();
  const davRoot = utils.uid || token;

  // Cache and uncached photos
  const cache: IFileInfo[] = [];
  const rest: IPhoto[] = [];

  // Partition photos with and without cache
  for (const photo of photos) {
    const filename = photo.imageInfo?.filename;
    if (filename) {
      cache.push(makeFileInfo(photo, filename, davRoot));
    } else {
      rest.push(photo);
    }
  }

  // Get file infos for the rest
  opts?.signal?.throwIfAborted();
  return [...cache, ...(await getFilesInternal1(rest, { ...opts, token }))];
}

function makeFileInfo(photo: IPhoto, filename: string, davRoot?: string): IFileInfo {
  return {
    id: photo.fileid,
    fileid: photo.fileid,
    basename: photo.basename ?? filename.split('/').pop() ?? '',
    originalFilename: `/files/${davRoot}${filename}`,
    filename: filename,
  };
}

async function getFilesInternal1(photos: IPhoto[], opts?: AbortOpts & { token?: string }): Promise<IFileInfo[]> {
  // For public shares, use API instead of WebDAV SEARCH (which requires user auth)
  if (!utils.uid) {
    return getFilesViaAPI(photos, opts);
  }

  // Get file IDs array
  const fileIds = photos.map((photo) => photo.fileid);

  // Divide fileIds into chunks of GET_FILE_CHUNK_SIZE
  const chunks: number[][] = [];
  for (let i = 0; i < fileIds.length; i += GET_FILE_CHUNK_SIZE) {
    chunks.push(fileIds.slice(i, i + GET_FILE_CHUNK_SIZE));
  }

  // Get file infos for each chunk
  const errors: unknown[] = [];
  const calls = chunks.map((c) => async () => {
    try {
      return await getFilesInternal2(c, opts);
    } catch (e) {
      if (isAbortError(e)) throw e;
      errors.push(e);
      return [];
    }
  });

  const fileInfos: IFileInfo[] = [];
  for await (const res of runInParallel(calls, GET_FILE_CONCURRENCY, opts)) {
    fileInfos.push(...res.flat());
  }

  if (errors.length) throw errors[0];
  return fileInfos;
}

/**
 * Get file infos for public shares via API (instead of WebDAV SEARCH)
 * This is needed because WebDAV SEARCH requires user authentication
 */
async function getFilesViaAPI(photos: IPhoto[], opts?: AbortOpts & { token?: string }): Promise<IFileInfo[]> {
  const signal = opts?.signal;
  const token = opts?.token;

  const calls = photos.map((photo) => async (): Promise<IFileInfo | null> => {
    try {
      signal?.throwIfAborted();
      const url = API.IMAGE_INFO(photo.fileid);
      const res = await axios.get<IImageInfo>(url, { signal });
      const filename = res.data.filename;
      if (!filename) return null;

      // Use token-based WebDAV path for public shares
      return makeFileInfo(photo, filename, token);
    } catch (error) {
      if (isAbortError(error)) throw error;
      console.error('Failed to get file info via API', photo.fileid, error);
      return null;
    }
  });

  const fileInfos: IFileInfo[] = [];
  for await (const res of runInParallel(calls, 8, opts)) {
    fileInfos.push(...res.filter(utils.truthy));
  }

  return fileInfos;
}

async function getFilesInternal2(fileIds: number[], opts?: AbortOpts): Promise<IFileInfo[]> {
  // This function is only called for authenticated users (not public shares)
  // Public shares use getFilesViaAPI instead to avoid WebDAV SEARCH auth issues
  const prefixPath = `/files/${utils.uid}`;
  const signal = opts?.signal;

  // IMPORTANT: if this isn't there, then a blank
  // returns EVERYTHING on the server!
  if (fileIds.length === 0) {
    return [];
  }

  const response = (await client.search('', {
    data: xml.searchByFileIds(prefixPath, fileIds),
    details: true,
    signal,
  })) as ResponseDataDetailed<SearchResult>;

  return response.data.results
    .filter((file) => file.props?.fileid)
    .map((file) => {
      // remote remotePath from start
      if (file.filename.startsWith(remotePath)) {
        file.filename = file.filename.slice(remotePath.length);
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
 * @details Tasks MUST resolve rather than throw. Abort errors propagate out
 * and stop iteration; any other task error is logged and skipped.
 * @returns Generator of lists of results. Each list is of length n.
 */
export async function* runInParallel<T>(promises: (() => Promise<T>)[], n: number, opts?: AbortOpts) {
  if (!promises.length) return;

  promises.reverse(); // reverse so we can use pop() efficiently

  const results: T[] = [];
  const running: Promise<void>[] = [];

  while (true) {
    opts?.signal?.throwIfAborted();

    // add one promise per iteration
    if (promises.length) {
      let task!: Promise<void>;
      running.push(
        (task = (async () => {
          try {
            // run the promise
            results.push(await promises.pop()!());
          } finally {
            // remove the promise from the running list
            running.splice(running.indexOf(task), 1);
          }
        })()),
      );
    }

    // wait for one of the promises to finish
    if (running.length >= n || !promises.length) {
      try {
        await Promise.race(running);
      } catch (e) {
        // Genuine cancellation of our generation: propagate it.
        if (isAbortError(e) && opts?.signal?.aborted) throw e;
        // Anything else is unexpected: log it and continue.
        console.error('[BUG] runInParallel: uncaught task error', e);
      }
    }

    // yield the results if the threshold is reached
    if (results.length >= n || !running.length) {
      yield results.splice(0, results.length);
    }

    // stop when all promises are done
    if (!running.length && !promises.length) break;
  }
}

type ExtendedStack = {
  photos: IPhoto[];
  livePhotoVideoFileIds: Set<number>;
};

/**
 * Extend given list of Ids with extra files for
 *
 * 1. Live Photos.
 * 2. Stacked RAW files.
 *
 * @param photos list of photos to search for
 *
 * @returns expanded photos and the IDs of Live Photo video parts
 */
export async function extendWithStack(photos: IPhoto[], opts?: AbortOpts): Promise<ExtendedStack> {
  const signal = opts?.signal;
  const livePhotos: IPhoto[] = [];
  for await (const res of runInParallel(
    photos
      .filter((p) => p.liveid && !p.liveid.startsWith('self__'))
      .map((p) => async () => {
        try {
          signal?.throwIfAborted();
          const base = utils.getLivePhotoVideoUrl(p, false);
          const url = API.Q(base, { format: 'json' });
          const res = await axios.get<IPhoto>(url, { signal });
          return res.data;
        } catch (error) {
          if (isAbortError(error)) throw error;
          console.error(error);
          return null;
        }
      }),
    10,
    opts,
  )) {
    livePhotos.push(...res.filter(utils.truthy));
  }

  // Add stacked RAW files (deduped)
  const stackRaw = photos.map((p) => p.stackraw ?? []).flat();

  // Combine all files
  const combined = [...photos, ...livePhotos, ...stackRaw];

  // De-duplicate keeping the order same as before
  // https://github.com/pulsejet/memories/issues/1056
  const cIds = new Set(combined.map((p) => p.fileid));
  return {
    photos: combined.filter((p) => cIds.delete(p.fileid)),
    livePhotoVideoFileIds: new Set(livePhotos.map((p) => p.fileid)),
  };
}

/**
 * Delete all files in a given list of Ids
 *
 * @param photos list of photos to delete
 * @param opts.confirm whether to show a confirmation dialog (default true)
 * @param opts.route current route for file info handling
 * @returns list of file ids that were deleted
 */
export async function* deletePhotos(
  photos: IPhoto[],
  opts: { confirm?: boolean; route: RouteLocationNormalized } & AbortOpts,
) {
  if (photos.length === 0) return;

  // Use user selected count for confirmation
  const confirmationCount = photos.length;
  let confirm = opts.confirm ?? true;
  const route = opts.route;
  const signal = opts.signal;

  // Extend with stack unless this is an album
  const isAlbum = routeIs(opts.route).Albums;
  let livePhotoVideoFileIds = new Set<number>();
  if (!isAlbum) {
    const extended = await extendWithStack(photos, opts);
    photos = extended.photos;
    livePhotoVideoFileIds = extended.livePhotoVideoFileIds;
  }

  // Get set of unique file ids
  const fileIdsSet = new Set(photos.map((p) => p.fileid));

  // Get files data. The double filter ensures we never delete something accidentally.
  const fileInfos = (await getFiles(photos, { route, signal })).filter((f) => fileIdsSet.has(f.fileid));
  const regularFileInfos = fileInfos.filter((f) => !livePhotoVideoFileIds.has(f.fileid));
  const livePhotoFileInfos = fileInfos.filter((f) => livePhotoVideoFileIds.has(f.fileid));

  // Check for locally available files and delete them.
  // For albums, we are not actually deleting.
  const hasNative = nativex.has() && !isAlbum;

  // Check if native confirmation is available
  if (hasNative) {
    confirm &&= (await nativex.deleteLocalPhotos(photos, true)) !== photos.length;
  }

  // Show confirmation dialog if required
  if (confirm) {
    if (isAlbum) {
      if (!(await utils.dialogs.removeFromAlbum(confirmationCount))) {
        throw new Error('User cancelled removal');
      }
    } else {
      if (!(await utils.dialogs.moveToTrash(confirmationCount))) {
        throw new Error('User cancelled deletion');
      }
    }
  }

  // Delete local files.
  signal?.throwIfAborted();
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

  /** Delete a server file. */
  const _delete_file = async (fileInfo: IFileInfo, silenceErrors: boolean = false) => {
    try {
      signal?.throwIfAborted();
      if (!utils.uid) {
        // Public share: use API endpoint which supports token authentication
        await axios.delete(API.IMAGE_DELETE(fileInfo.fileid), { signal });
      } else {
        // Authenticated user: use WebDAV
        await client.deleteFile(fileInfo.originalFilename, { signal });
      }
      return fileInfo.fileid;
    } catch (error) {
      if (isAbortError(error)) throw error;
      if (silenceErrors) return 0;
      console.error('Failed to delete', fileInfo, error);
      showError(
        t('memories', 'Failed to delete {fileName}.', {
          fileName: fileInfo.filename,
        }),
      );
      return 0;
    }
  };

  const calls = regularFileInfos.map((fileInfo) => async () => _delete_file(fileInfo));
  yield* runInParallel(calls, 10, opts);
  // Try to delete the live photo video parts but silently ignore errors
  const livePhotoVideoCalls = livePhotoFileInfos.map((fileInfo) => () => _delete_file(fileInfo, true));
  for await (const _ of runInParallel(livePhotoVideoCalls, 10, opts)) {
    // ignore results for live photo video operations
  }
}

type TransferOpts = { route: RouteLocationNormalized } & AbortOpts;
type TransferOperation = { fileInfo: IFileInfo; target: string };
type TransferFn = (from: string, to: string, opts: { overwrite: boolean } & AbortOpts) => Promise<void>;

type TransferMessages = {
  all: () => string;
  exists: (fileName: string) => string;
  failed: (fileName: string) => string;
};

const COPY_MESSAGES: TransferMessages = {
  all: () => t('memories', 'Failed to copy files.'),
  exists: (fileName) => t('memories', 'Could not copy {fileName}, target exists.', { fileName }),
  failed: (fileName) => t('memories', 'Failed to copy {fileName}.', { fileName }),
};

const MOVE_MESSAGES: TransferMessages = {
  all: () => t('memories', 'Failed to move files.'),
  exists: (fileName) => t('memories', 'Could not move {fileName}, target exists.', { fileName }),
  failed: (fileName) => t('memories', 'Failed to move {fileName}.', { fileName }),
};

/**
 * Expand photos with their stacks and get the file info for each.
 * @returns null if the file infos could not be loaded (the user is notified)
 */
async function resolveTransfer(photos: IPhoto[], messages: TransferMessages, opts: TransferOpts) {
  const extended = await extendWithStack(photos, opts);
  const photoMap = new Map(extended.photos.map((p) => [p.fileid, p]));

  try {
    const fileInfos = await getFiles(extended.photos, { route: opts.route, signal: opts.signal });
    return {
      photoMap,
      fileInfos: fileInfos.filter((f) => photoMap.has(f.fileid)),
      livePhotoVideoFileIds: extended.livePhotoVideoFileIds,
    };
  } catch (e) {
    if (isAbortError(e)) throw e;
    console.error('Failed to get file info for files to transfer', photos, e);
    showError(messages.all());
    return null;
  }
}

/**
 * Run transfers, then transfer Live Photo video parts silently ignoring errors.
 * @returns generator of lists of file ids that were transferred
 */
async function* runTransfers(
  operations: TransferOperation[],
  livePhotoVideoFileIds: Set<number>,
  transfer: TransferFn,
  overwrite: boolean,
  messages: TransferMessages,
  opts: AbortOpts,
) {
  const signal = opts.signal;

  const call =
    ({ fileInfo, target }: TransferOperation, silenceErrors: boolean = false) =>
    async () => {
      try {
        signal?.throwIfAborted();
        await transfer(fileInfo.originalFilename, target, { overwrite, signal });
        return fileInfo.fileid;
      } catch (error: any) {
        if (isAbortError(error)) throw error;
        if (silenceErrors) return 0;
        console.error('Failed to transfer', fileInfo, error);

        // Precondition failed (only if `overwrite` flag set to false)
        const message = error.response?.status === 412 ? messages.exists : messages.failed;
        showError(message(fileInfo.filename));
        return 0;
      }
    };

  const isLivePhotoVideo = (op: TransferOperation) => livePhotoVideoFileIds.has(op.fileInfo.fileid);

  const calls = operations.filter((op) => !isLivePhotoVideo(op)).map((op) => call(op));
  yield* runInParallel(calls, 10, opts);
  const livePhotoVideoCalls = operations.filter(isLivePhotoVideo).map((op) => call(op, true));
  for await (const _ of runInParallel(livePhotoVideoCalls, 10, opts)) {
    // ignore results for live photo video operations
  }
}

/** Transfer all files in a given list of Ids to a destination folder. */
async function* transferToFolder(
  photos: IPhoto[],
  destination: string,
  overwrite: boolean,
  transfer: TransferFn,
  messages: TransferMessages,
  opts: TransferOpts,
) {
  if (photos.length === 0) return;

  // Set absolute target path
  let targetPath = `files/${utils.uid}${destination}`;
  if (!targetPath.endsWith('/')) {
    targetPath += '/';
  }

  const resolved = await resolveTransfer(photos, messages, opts);
  if (!resolved) return;

  const operations = resolved.fileInfos.map((fileInfo) => ({ fileInfo, target: targetPath + fileInfo.basename }));
  yield* runTransfers(operations, resolved.livePhotoVideoFileIds, transfer, overwrite, messages, opts);
}

/**
 * copy all files in a given list of Ids to given destination
 *
 * @param photos list of photos to copy
 * @param destination to copy photos into
 * @param overwrite behaviour if the target exists. `true` overwrites, `false` fails.
 * @returns list of file ids that were copied
 */
export function copyPhotos(photos: IPhoto[], destination: string, overwrite: boolean, opts: TransferOpts) {
  return transferToFolder(photos, destination, overwrite, client.copyFile, COPY_MESSAGES, opts);
}

/**
 * Move all files in a given list of Ids to given destination
 *
 * @param photos list of photos to move
 * @param destination to move photos into
 * @param overwrite behaviour if the target exists. `true` overwrites, `false` fails.
 * @returns list of file ids that were moved
 */
export function movePhotos(photos: IPhoto[], destination: string, overwrite: boolean, opts: TransferOpts) {
  return transferToFolder(photos, destination, overwrite, client.moveFile, MOVE_MESSAGES, opts);
}

/**
 * Move multiple files in given lists of Ids to corresponding destinations in a year/month folder structure.
 *
 * @param photos list of photos to move
 * @param destination to move photos into
 * @param overwrite behaviour if the target exists. `true` overwrites, `false` fails.
 * @returns list of file ids that were moved
 */
export async function* movePhotosByDate(photos: IPhoto[], destination: string, overwrite: boolean, opts: TransferOpts) {
  if (photos.length === 0) return;

  // Set absolute target path
  destination = `files/${utils.uid}/${destination}`;

  const resolved = await resolveTransfer(photos, MOVE_MESSAGES, opts);
  if (!resolved) return;

  // Create the list of operations
  const operations: (TransferOperation & { folder: string })[] = [];
  for (const fileInfo of resolved.fileInfos) {
    const photo = resolved.photoMap.get(fileInfo.fileid);
    if (!photo?.dayid) continue;

    const date = utils.dayIdToDate(photo.dayid);
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');

    const folder = `${destination}/${year}/${month}`;
    operations.push({ fileInfo, folder, target: `${folder}/${fileInfo.basename}` });
  }

  // Cache confirmed existing directories
  const existingDirCache = new Set<string>();

  // Create all the folders first
  try {
    for (const { folder } of operations) {
      let folderPath = folder;
      const createPaths = new Array<string>();

      while (folderPath !== destination && folderPath.includes('/')) {
        if (existingDirCache.has(folderPath) || (await client.exists(folderPath, { signal: opts.signal }))) {
          // No need to check further up. Go to creation.
          existingDirCache.add(folderPath);
          break;
        }

        createPaths.push(folderPath);
        folderPath = folderPath.slice(0, folderPath.lastIndexOf('/'));
      }

      // Create from top to bottom
      for (const path of createPaths.reverse()) {
        await client.createDirectory(path, { signal: opts.signal });
        existingDirCache.add(path);
      }
    }
  } catch (e) {
    if (isAbortError(e)) throw e;
    console.error('Failed to create folders for files to move', e);
    showError(MOVE_MESSAGES.all());
    return;
  }

  yield* runTransfers(operations, resolved.livePhotoVideoFileIds, client.moveFile, overwrite, MOVE_MESSAGES, opts);
}

/**
 * Fill the imageInfo attributes of the given photos
 *
 * @param photos list of photos to fill
 * @param query options
 * @param query.tags whether to include tags in the response
 * @param progress callback to report progress
 */
export async function fillImageInfo(
  photos: IPhoto[],
  query?: { tags?: number },
  progress?: (count: number) => void,
  opts?: AbortOpts,
) {
  // Filter out photos that are local only
  const remote = photos.filter((p) => !utils.isLocalPhoto(p));
  const signal = opts?.signal;

  // Number of photos done
  let done = photos.length - remote.length;
  if (done > 0) progress?.(done);

  // Load metadata for all photos
  const calls = remote.map((p) => async () => {
    try {
      signal?.throwIfAborted();
      const url = API.Q(API.IMAGE_INFO(p.fileid), query ?? {});
      const res = await axios.get<IImageInfo>(url, { signal });
      p.datetaken = res.data.datetaken;
      p.imageInfo = res.data;
    } catch (error) {
      if (isAbortError(error)) throw error;
      console.error('Failed to get image info', p, error);
      showError(t('memories', 'Failed to load image info: {name}', { name: p.basename ?? p.fileid }));
    } finally {
      done++;
      progress?.(done);
    }
  });

  for await (const _ of runInParallel(calls, 8, opts)) {
    // nothing to do
  }
}

/**
 * Force reindex the given photos, one file per request.
 *
 * @param photos list of photos to reindex
 * @param progress callback to report number of files done
 * @returns number of files successfully reindexed
 */
export async function reindexPhotos(photos: IPhoto[], progress?: (done: number) => void, opts?: AbortOpts) {
  const remote = photos.filter((p) => !utils.isLocalPhoto(p));
  const signal = opts?.signal;

  let done = photos.length - remote.length;
  if (done > 0) progress?.(done);
  let success = 0;

  const calls = remote.map((p) => async () => {
    try {
      signal?.throwIfAborted();
      await axios.post(API.IMAGE_REINDEX(p.fileid), undefined, { signal });
      success++;
    } catch (error) {
      if (isAbortError(error)) throw error;
      console.error('Failed to reindex', p.fileid, error);
      showError(t('memories', 'Failed to refresh metadata for {name}.', { name: p.basename ?? p.fileid }));
    } finally {
      done++;
      progress?.(done);
    }
  });

  for await (const _ of runInParallel(calls, 4, opts)) {
    // nothing to do
  }

  return success;
}
