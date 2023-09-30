import { showError } from '@nextcloud/dialogs';
import { translate as t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';

import { IFileInfo, IPhoto } from '../../types';
import { API } from '../API';
import { getAlbumFileInfos } from './albums';
import client from './client';
import * as utils from '../utils';
import * as nativex from '../../native';

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
    const route = vueroute();

    // Check if albums
    if (route.name === 'albums') {
      return getAlbumFileInfos(photos, <string>route.params.user, <string>route.params.name);
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

  const options = {
    method: 'SEARCH',
    headers: { 'content-Type': 'text/xml' },
    data: `<?xml version="1.0" encoding="UTF-8"?><d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns" xmlns:ns="https://github.com/icewind1991/SearchDAV/ns" xmlns:ocs="http://open-collaboration-services.org/ns"><d:basicsearch><d:select><d:prop><oc:fileid /></d:prop></d:select><d:from><d:scope><d:href>${prefixPath}</d:href><d:depth>0</d:depth></d:scope></d:from><d:where><d:or>${filter}</d:or></d:where></d:basicsearch></d:searchrequest>`,
    deep: true,
    details: true,
  };

  const response: any = await client.getDirectoryContents('', options);

  return response.data.map((data: any) => ({
    id: data.props.fileid,
    fileid: data.props.fileid,
    basename: data.basename,
    originalFilename: data.filename,
    filename: data.filename.replace(prefixPath, ''),
  }));
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
        })())
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
    if (!running.length) break;
  }
}

/**
 * Extend given list of Ids with extra files for Live Photos.
 *
 * @param photos list of photos to search for Live Photos
 * @returns list of file ids that contains extra file Ids for Live Photos if any
 */
async function extendWithLivePhotos(photos: IPhoto[]) {
  const livePhotos = (
    await Promise.all(
      photos
        .filter((p) => p.liveid && !p.liveid.startsWith('self__'))
        .map(async (p) => {
          try {
            const url = API.Q(utils.getLivePhotoVideoUrl(p, false), { format: 'json' });
            return (await axios.get<IPhoto>(url)).data;
          } catch (error) {
            console.error(error);
            return null;
          }
        })
    )
  ).filter((p) => p !== null) as IPhoto[];

  return photos.concat(livePhotos);
}

/**
 * Delete all files in a given list of Ids
 *
 * @param photos list of photos to delete
 * @returns list of file ids that were deleted
 */
export async function* deletePhotos(photos: IPhoto[]) {
  if (photos.length === 0) return;

  // Extend with Live Photos unless this is an album
  const routeIsAlbums = window.vueroute().name === 'albums';
  if (!routeIsAlbums) {
    photos = await extendWithLivePhotos(photos);
  }

  // Get set of unique file ids
  let fileIds = new Set(photos.map((p) => p.fileid));

  // Get files data. The double filter ensures we never delete something accidentally.
  const fileInfos = (await getFiles(photos)).filter((f) => fileIds.has(f.fileid));

  // Take intersection of fileIds and fileInfos
  fileIds = new Set(fileInfos.map((f) => f.fileid));

  // Check for locally available files and delete them.
  // For albums, we are not actually deleting.
  if (nativex.has() && !routeIsAlbums) {
    // Delete local files. This will throw if user cancels.
    await nativex.deleteLocalPhotos(photos);

    // Remove purely local files
    const deleted = photos.filter((p) => p.flag & utils.constants.c.FLAG_IS_LOCAL);

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
        })
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

  // Also move the Live Photo videos
  photos = await extendWithLivePhotos(photos);
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
        { headers: { Overwrite: overwrite ? 'T' : 'F' } }
      );
      return fileInfo.fileid;
    } catch (error) {
      console.error('Failed to move', fileInfo, error);
      if (error.response?.status === 412) {
        // Precondition failed (only if `overwrite` flag set to false)
        showError(
          t('memories', 'Could not move {fileName}, target exists.', {
            fileName: fileInfo.filename,
          })
        );
        return 0;
      }

      showError(
        t('memories', 'Failed to move {fileName}.', {
          fileName: fileInfo.filename,
        })
      );
      return 0;
    }
  });

  yield* runInParallel(calls, 10);
}
