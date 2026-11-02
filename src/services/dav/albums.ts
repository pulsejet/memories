import * as base from './base';

import axios from '@nextcloud/axios';
import { showError } from '@services/utils/dialog';
import { getLanguage } from '@nextcloud/l10n';

import { translate as t, translatePlural as n } from '@services/l10n';
import { API } from '@services/API';
import { config } from '@services/user-config';
import { constants } from '@services/constants';
import { isAbortError } from '@services/utils/abort';
import client from '@services/dav/client';
import * as utils from '@services/utils/common';

import type { IAlbum, IFileInfo, IPhoto } from '@typings';
import type { AbortOpts } from '@services/utils/abort';

export type IDavAlbum = {
  basename: string;
  filename: string;
  location: string;
  collaborators: {
    id: string;
    label: string;
    type: number;
  }[];
};

/**
 * Get DAV path for album
 */
export function getAlbumPath(user: string, name: string) {
  // Folder in the dav collection for user
  if (user === utils.uid) {
    return `/photos/${utils.uid}/albums/${name}`;
  } else {
    return `/photos/${utils.uid}/sharedalbums/${name} (${user})`;
  }
}

/**
 * Get list of albums.
 * @param opts.fileid Optional file ID to get albums for
 */
export async function getAlbums(opts?: { fileid?: number } & AbortOpts) {
  const { fileid, signal } = opts ?? {};
  const url = API.Q(API.ALBUM_LIST(), { fileid });
  const res = await axios.get<IAlbum[]>(url, { signal });
  let data = res.data;

  // Remove hidden albums unless specified
  if (!config.show_hidden_albums) {
    data = data.filter((a) => !a.name.startsWith('.'));
  }

  // Sort the response
  const sort = config.album_list_sort;
  if (sort & constants.ALBUM_SORT_FLAGS.NAME) {
    data.sort((a, b) => a.name.localeCompare(b.name, getLanguage(), { numeric: true }));
  } else if (sort & constants.ALBUM_SORT_FLAGS.LAST_UPDATE) {
    data.sort((a, b) => (a.update_id ?? Number.MAX_SAFE_INTEGER) - (b.update_id ?? Number.MAX_SAFE_INTEGER));
  } else {
    // fall back to created date
    data.sort((a, b) => a.created - b.created);
  }

  // Sort descending if needed
  if (sort & constants.ALBUM_SORT_FLAGS.DESCENDING) {
    data.reverse();
  }

  return data;
}

/**
 * Add photos to an album.
 *
 * @param user User ID of album
 * @param name Name of album (or ID)
 * @param photos List of photos to add
 * @returns Generator
 */
export async function* addToAlbum(user: string, name: string, photos: IPhoto[], opts?: AbortOpts) {
  // Get files data
  const fileInfos = await base.getFiles(photos, { ignoreRoute: true, signal: opts?.signal });
  const albumPath = getAlbumPath(user, name);

  // Add each file
  const calls = fileInfos.map((f) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      await client.copyFile(f.originalFilename, `${albumPath}/${f.basename}`, { signal: opts?.signal });
      return f.fileid;
    } catch (e: any) {
      if (isAbortError(e)) throw e;
      if (e.response?.status === 409) {
        // File already exists, all good
        return f.fileid;
      }

      showError(t('memories', 'Failed to add {filename} to album.', { filename: f.filename }));
      console.error('DAV COPY error', e.response?.data);
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10, opts);
}

/**
 * Remove photos from an album.
 *
 * @param user Owner of album
 * @param name Name of album (or ID)
 * @param photos List of photos to remove
 * @returns Generator
 */
export async function* removeFromAlbum(user: string, name: string, photos: IPhoto[], opts?: AbortOpts) {
  // Get files data
  const fileInfos = await base.getFiles(photos, { ignoreRoute: true, signal: opts?.signal });
  const albumPath = getAlbumPath(user, name);

  // Remove each file
  const calls = fileInfos.map((f) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      await client.deleteFile(`${albumPath}/${f.fileid}-${f.basename}`, { signal: opts?.signal });
      return f.fileid;
    } catch (e) {
      if (isAbortError(e)) throw e;
      showError(
        t('memories', 'Failed to remove {filename}.', {
          filename: f.basename ?? f.fileid,
        }),
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10, opts);
}

/**
 * Create an album.
 */
export async function createAlbum(albumName: string, opts?: AbortOpts) {
  await client.createDirectory(`/photos/${utils.uid}/albums/${albumName}`, { signal: opts?.signal });
}

/**
 * Update an album's properties.
 *
 * @param album Album to update
 * @param properties The properties to update
 */
export async function updateAlbum(
  album: { filename: string },
  properties: Partial<Pick<IDavAlbum, 'location' | 'collaborators'>>,
  opts?: AbortOpts,
): Promise<void> {
  const stringifiedProperties = Object.entries(properties)
    .filter(([, value]) => value !== undefined)
    .map(([name, value]) => {
      const text = typeof value === 'string' ? value : JSON.stringify(value);
      return `<nc:${name}>${utils.escapeXml(text)}</nc:${name}>`;
    })
    .join('');

  opts?.signal?.throwIfAborted();
  await client.customRequest(album.filename, {
    method: 'PROPPATCH',
    data: `<?xml version="1.0"?>
                        <d:propertyupdate xmlns:d="DAV:"
                            xmlns:oc="http://owncloud.org/ns"
                            xmlns:nc="http://nextcloud.org/ns"
                            xmlns:ocs="http://open-collaboration-services.org/ns">
                        <d:set>
                            <d:prop>
                                ${stringifiedProperties}
                            </d:prop>
                        </d:set>
                        </d:propertyupdate>`,
    signal: opts?.signal,
  });
}

/**
 * Get one album from DAV collection
 * @param user Owner of album
 * @param name Name of album (or ID)
 */
export async function getAlbum(user: string, name: string, opts?: AbortOpts): Promise<IDavAlbum> {
  const req = `<?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:"
            xmlns:oc="http://owncloud.org/ns"
            xmlns:nc="http://nextcloud.org/ns"
            xmlns:ocs="http://open-collaboration-services.org/ns">
            <d:prop>
                <nc:location />
                <nc:collaborators />
            </d:prop>
        </d:propfind>`;
  let album = (await client.stat(getAlbumPath(user, name), {
    data: req,
    details: true,
    signal: opts?.signal,
  })) as any;

  // Post processing
  album = {
    ...album.data,
    ...album.data.props,
  };
  const c = album?.collaborators?.collaborator;
  album.collaborators = c ? (Array.isArray(c) ? c : [c]) : [];

  // Sort collaborators by type
  album.collaborators.sort((a: any, b: any) => {
    return (a.type ?? -1) - (b.type ?? -1);
  });

  return album;
}

/** Rename an album */
export async function renameAlbum<T extends object>(
  album: T,
  currentAlbumName: string,
  newAlbumName: string,
  opts?: AbortOpts,
): Promise<T> {
  opts?.signal?.throwIfAborted();
  const filenameBefore = `/photos/${utils.uid}/albums/${currentAlbumName}`;
  const filenameAfter = `/photos/${utils.uid}/albums/${newAlbumName}`;
  await client.moveFile(filenameBefore, filenameAfter, { signal: opts?.signal });
  return { ...album, basename: newAlbumName, filename: filenameAfter };
}

/** Get fileinfo objects from album photos */
export function getAlbumFileInfos(photos: IPhoto[], albumUser: string, albumName: string): IFileInfo[] {
  const collection =
    albumUser === utils.uid
      ? `/photos/${utils.uid}/albums/${albumName}`
      : `/photos/${utils.uid}/sharedalbums/${albumName} (${albumUser})`;

  return photos.map((photo) => {
    const basename = `${photo.fileid}-${photo.basename}`;
    return {
      fileid: photo.fileid,
      filename: `${collection}/${basename}`,
      originalFilename: `${collection}/${basename}`,
      basename: basename,
    } as IFileInfo;
  });
}

export function getAlbumSubtitle(album: IAlbum) {
  let text: string;
  if (album.count === 0) {
    text = t('memories', 'No items');
  } else {
    text = n('memories', '{n} item', '{n} items', album.count, { n: album.count });
  }

  if (album.user !== utils.uid) {
    const sharer = t('memories', 'Shared by {user}', {
      user: album.user_display || album.user,
    });
    text = `${text} | ${sharer}`;
  } else if (album.shared) {
    const shared = t('memories', 'Shared Album');
    text = `${text} • ${shared}`;
  }

  return text;
}
