import axios from '@nextcloud/axios';
import { generateRemoteUrl } from '@nextcloud/router';

import client from '@services/dav/client';
import { runInParallel } from '@services/dav/base';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { isAbortError } from '@services/utils/abort';
import { showError, showSuccess } from '@services/utils/dialog';
import * as utils from '@services/utils/common';

import type { AbortOpts } from '@services/utils/abort';
import type { IDay, IPhoto } from '@typings';

export function getTrashRoot(): string {
  return `/trashbin/${utils.uid}/trash`;
}

export function getTrashRestoreRoot(): string {
  return `/trashbin/${utils.uid}/restore`;
}

type ITrashHit = {
  fileid: number;
  deletion_time: number;
  basename: string;
  mimetype: string;
  size: number;
  etag: string;
  trash_path: string;
  orig_path: string;
};

function hitToPhoto(hit: ITrashHit): IPhoto | null {
  const fileid = Number(hit.fileid ?? 0);
  if (!fileid) return null;

  const deletionTime = Number(hit.deletion_time ?? 0);
  const mimetype: string = hit.mimetype ?? '';
  const dayid = deletionTime ? utils.dateToDayId(new Date(deletionTime * 1000)) : 0;

  return {
    fileid,
    key: `trash-${fileid}`,
    dayid,
    basename: hit.basename ?? '',
    mimetype,
    size: Number(hit.size ?? 0),
    etag: hit.etag,
    flag: 0,
    isvideo: mimetype.startsWith('video/'),
    trashInfo: {
      trashPath: hit.trash_path,
      origPath: hit.orig_path || undefined,
    },
  };
}

/**
 * Fake trash days for the Timeline, grouped by deletion day (newest first).
 * Days carry preloaded detail like lens search, so no day API calls are made.
 */
export async function getTrashDays(opts?: AbortOpts): Promise<IDay[]> {
  const res = await axios.get<ITrashHit[]>(API.TRASH_LIST(), { signal: opts?.signal });
  opts?.signal?.throwIfAborted();

  const seen = new Set<number>();
  const groups = new Map<number, IPhoto[]>();
  for (const hit of res.data ?? []) {
    const p = hitToPhoto(hit);
    if (!p || seen.has(p.fileid)) continue;
    seen.add(p.fileid);
    let detail = groups.get(p.dayid);
    if (!detail) groups.set(p.dayid, (detail = []));
    detail.push(p);
  }

  return [...groups.entries()]
    .sort((a, b) => b[0] - a[0])
    .map(([dayid, detail]) => ({
      dayid,
      count: detail.length,
      // Newest deletions first within the day (fileids grow with time)
      detail: detail.sort((a, b) => b.fileid - a.fileid),
    }));
}

/**
 * Restore trash items by MOVE to restore folder.
 */
export async function* restoreTrashPhotos(photos: IPhoto[], opts?: AbortOpts) {
  if (!photos.length) return;
  const restoreRoot = getTrashRestoreRoot();

  const calls = photos.map((p) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      const trashPath = p.trashInfo!.trashPath;
      const rawBasename = trashPath.split('/').pop()!;
      await client.moveFile(trashPath, `${restoreRoot}/${rawBasename}`, { signal: opts?.signal } as any);
      return p.fileid;
    } catch (e) {
      if (isAbortError(e)) throw e;
      console.error('Failed to restore', p, e);
      showError(t('memories', 'Failed to restore {fileName}.', { fileName: p.basename }));
      return 0;
    }
  });

  yield* runInParallel(calls, 5, opts);
}

/**
 * Permanently delete trash items.
 */
export async function* deleteTrashPhotos(photos: IPhoto[], opts?: AbortOpts) {
  if (!photos.length) return;

  const calls = photos.map((p) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      await client.deleteFile(p.trashInfo!.trashPath, { signal: opts?.signal } as any);
      return p.fileid;
    } catch (e) {
      if (isAbortError(e)) throw e;
      console.error('Failed to delete permanently', p, e);
      showError(t('memories', 'Failed to delete {fileName}.', { fileName: p.basename }));
      return 0;
    }
  });

  yield* runInParallel(calls, 5, opts);
}

/** Direct download link for a trashed file (e.g. for video playback, no transcoding) */
export function getTrashDownloadLink(photo: IPhoto): string {
  return client.getFileDownloadLink(photo.trashInfo!.trashPath);
}

/** Empty entire trashbin */
export async function emptyTrashbin(opts?: AbortOpts): Promise<boolean> {
  try {
    const url = generateRemoteUrl(`dav${getTrashRoot()}`);
    await axios.delete(url, { signal: opts?.signal });
    showSuccess(t('memories', 'Trash emptied'));
    return true;
  } catch (e) {
    if (isAbortError(e)) throw e;
    console.error('Failed to empty trash', e);
    showError(t('memories', 'Failed to empty trash.'));
    return false;
  }
}
