import axios from '@nextcloud/axios';
import { generateRemoteUrl } from '@nextcloud/router';
import { getDavNameSpaces, getDavProperties } from '@nextcloud/files/dav';

import client, { remotePath } from '@services/dav/client';
import { runInParallel } from '@services/dav/base';

import { translate as t } from '@services/l10n';
import { isAbortError } from '@services/utils/abort';
import { showError, showSuccess } from '@services/utils/dialog';
import * as utils from '@services/utils/common';

import type { AbortOpts } from '@services/utils/abort';
import type { IDay, IPhoto } from '@typings';
import type { FileStat, ResponseDataDetailed } from 'webdav';

export function getTrashRoot(): string {
  return `/trashbin/${utils.uid}/trash`;
}

export function getTrashRestoreRoot(): string {
  return `/trashbin/${utils.uid}/restore`;
}

function stripTimestamp(name: string): string {
  return name.replace(/\.d\d+$/, '');
}

function getProp(props: any, name: string): any {
  if (!props) return undefined;
  if (props[name] !== undefined) return props[name];
  // namespaced variants e.g. {http://nextcloud.org/ns}trashbin-deletion-time
  for (const k of Object.keys(props)) {
    if (k.endsWith(`}${name}`) || k.endsWith(`:${name}`)) return props[k];
  }
  return undefined;
}

function statToPhoto(stat: FileStat): IPhoto | null {
  let filename: string = (stat as any).filename ?? '';
  if (filename.startsWith(remotePath)) {
    filename = filename.slice(remotePath.length);
  }

  const props: any = (stat as any).props ?? {};
  const fileid = Number(getProp(props, 'fileid') ?? (stat as any).fileid ?? 0);
  if (!fileid) return null;

  if ((stat as any).type === 'directory') return null;

  const mimetype: string = (stat as any).mime ?? (stat as any).mimetype ?? getProp(props, 'getcontenttype') ?? '';
  if (mimetype && !(mimetype.startsWith('image/') || mimetype.startsWith('video/'))) {
    const raw = (stat as any).basename ?? '';
    const stripped = stripTimestamp(raw).toLowerCase();
    if (!/\.(jpg|jpeg|png|gif|webp|heic|heif|bmp|tiff?|svg|avif|mp4|mov|m4v|mkv|webm|avi)$/.test(stripped)) {
      return null;
    }
  }

  const rawBasename: string = (stat as any).basename ?? filename.split('/').pop() ?? '';
  const originalLocation: string =
    getProp(props, 'trashbin-original-location') ?? getProp(props, 'trashbin-title') ?? '';
  const displayFromOriginal = originalLocation ? originalLocation.split('/').pop()! : '';
  const basename = displayFromOriginal || stripTimestamp(rawBasename);

  const deletionProp = getProp(props, 'trashbin-deletion-time');
  let deletionTime = Number(deletionProp ?? 0);
  if (!deletionTime && (stat as any).lastmod) {
    const parsed = Date.parse((stat as any).lastmod);
    if (Number.isFinite(parsed)) deletionTime = Math.floor(parsed / 1000);
  }
  if (!Number.isFinite(deletionTime)) deletionTime = 0;

  const size = Number((stat as any).size ?? getProp(props, 'size') ?? getProp(props, 'getcontentlength') ?? 0);

  return {
    fileid,
    key: `trash-${fileid}`,
    dayid: deletionTime ? utils.dateToDayId(new Date(deletionTime * 1000)) : 0,
    basename,
    mimetype,
    size,
    etag: (stat as any).etag,
    flag: 0,
    isvideo: mimetype.startsWith('video/'),
    trashInfo: {
      trashPath: filename,
      origPath: originalLocation || undefined,
    },
  };
}

function trashPropfindXml(): string {
  return `<?xml version="1.0"?>
<d:propfind ${getDavNameSpaces()}>
  <d:prop>
    <nc:trashbin-deletion-time />
    <nc:trashbin-original-location />
    <nc:trashbin-title />
    <nc:trashbin-deleted-by-id />
    <nc:trashbin-deleted-by-display-name />
    ${getDavProperties()}
  </d:prop>
</d:propfind>`;
}

async function listTrashDir(path: string, opts?: AbortOpts): Promise<FileStat[]> {
  opts?.signal?.throwIfAborted();
  const res = (await client.getDirectoryContents(path, {
    details: true,
    data: trashPropfindXml(),
    includeSelf: false,
    signal: opts?.signal,
  } as any)) as ResponseDataDetailed<FileStat[]>;
  return res?.data ?? [];
}

async function listTrashDirRecursive(path: string, opts?: AbortOpts): Promise<FileStat[]> {
  const direct = await listTrashDir(path, opts);
  const all: FileStat[] = [...direct];
  const subdirs: string[] = [];
  for (const s of direct) {
    let filename: string = (s as any).filename ?? '';
    if (filename.startsWith(remotePath)) filename = filename.slice(remotePath.length);
    if ((s as any).type === 'directory') subdirs.push(filename);
  }
  for (const sub of subdirs) {
    opts?.signal?.throwIfAborted();
    all.push(...(await listTrashDirRecursive(sub, opts)));
  }
  return all;
}

/**
 * Fake trash days for the Timeline, grouped by deletion day (newest first).
 * Days carry preloaded detail like lens search, so no day API calls are made.
 */
export async function getTrashDays(opts?: AbortOpts): Promise<IDay[]> {
  const root = getTrashRoot();
  let rootStats: FileStat[];
  try {
    rootStats = await listTrashDir(root, opts);
  } catch (e) {
    if (isAbortError(e)) throw e;
    console.error('Failed to list trashbin', e);
    throw e;
  }
  opts?.signal?.throwIfAborted();

  const dirs: string[] = [];
  for (const s of rootStats) {
    let filename: string = (s as any).filename ?? '';
    if (filename.startsWith(remotePath)) filename = filename.slice(remotePath.length);
    if ((s as any).type === 'directory') dirs.push(filename);
  }

  const calls = dirs.map((d) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      return await listTrashDirRecursive(d, opts);
    } catch (e) {
      if (isAbortError(e)) throw e;
      console.error('Failed to list trash subdir', d, e);
      return [] as FileStat[];
    }
  });

  const all = [...rootStats];
  for await (const batch of runInParallel(calls, 5, opts)) {
    for (const stats of batch) all.push(...stats);
    opts?.signal?.throwIfAborted();
  }

  const seen = new Set<number>();
  const groups = new Map<number, IPhoto[]>();
  for (const s of all) {
    const p = statToPhoto(s);
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
