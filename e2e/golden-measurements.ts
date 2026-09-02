import * as fs from 'fs';
import * as path from 'path';
import * as crypto from 'crypto';

import datasetJson from './dataset.json';
import type { IDay, IImageInfo, IPhoto } from '@typings';
import type { IDatasetEntry, IDatasetMap } from './generate-dataset';

const baseAssetsDir = path.join(__dirname, 'assets');
const dataset: IDatasetMap = datasetJson satisfies IDatasetMap;

export function parseExifDate(dateStr: string): { epoch: number; dayid: number } {
  let iso = dateStr.replace(/^(\d{4}):(\d{2}):(\d{2}) /, '$1-$2-$3T');
  if (!iso.includes('+') && !iso.endsWith('Z')) {
    iso += 'Z';
  }
  const date = new Date(iso);
  const epoch = Math.floor(date.getTime() / 1000);
  const dayid = Math.floor(epoch / 86400);
  return { epoch, dayid };
}

export function getAUID(epoch: number, size: number): string {
  return crypto.createHash('md5').update(`${epoch}${size}`).digest('hex');
}

/**
 * Get map of dayid -> IPhoto[] derived from dataset.
 */
export function goldDayMap(timelinePath: string, isArchive: boolean = false): Map<number, IPhoto[]> {
  const normTimelinePath = timelinePath.endsWith('/') ? timelinePath : `${timelinePath}/`;
  const dayMap = new Map<number, IPhoto[]>();

  for (const [relPath, entry] of Object.entries(dataset)) {
    if (!relPath.startsWith(normTimelinePath)) {
      continue;
    }

    const hasArchive = relPath.includes('/.archive/') || relPath.includes('/.archive');
    if (isArchive !== hasArchive) {
      continue;
    }

    const basename = path.basename(relPath);
    const filePath = path.join(baseAssetsDir, relPath);
    const fileSize = fs.existsSync(filePath) ? fs.statSync(filePath).size : 0;

    const { epoch, dayid } = parseExifDate(entry.exif.DateTimeOriginal || '');
    const auid = getAUID(epoch, fileSize);

    const photo: IPhoto = {
      fileid: 0,
      dayid,
      w: entry.size[0],
      h: entry.size[1],
      basename,
      epoch,
      mimetype: 'image/jpeg',
      auid,
      flag: 0,
    };

    if (!dayMap.has(dayid)) {
      dayMap.set(dayid, []);
    }
    dayMap.get(dayid)!.push(photo);
  }

  for (const photos of dayMap.values()) {
    photos.sort((a, b) => (b.epoch ?? 0) - (a.epoch ?? 0) || (b.basename ?? '').localeCompare(a.basename ?? ''));
  }

  return dayMap;
}

/**
 * Get golden days array derived from dataset.
 */
export function goldDays(timelinePath: string, isArchive: boolean = false): IDay[] {
  const dayMap = goldDayMap(timelinePath, isArchive);
  return Array.from(dayMap.entries())
    .map(([dayid, photos]) => ({ dayid, count: photos.length }))
    .sort((a, b) => b.dayid - a.dayid);
}

/**
 * Get golden photos for a single day derived from dataset.
 */
export function goldDayPhotos(timelinePath: string, dayid: number, isArchive: boolean = false): IPhoto[] {
  const dayMap = goldDayMap(timelinePath, isArchive);
  return dayMap.get(dayid) ?? [];
}

/**
 * Get golden image info object for a dataset entry.
 */
export function goldImageInfo(relPath: string): IImageInfo {
  const entry = dataset[relPath] as IDatasetEntry | undefined;
  if (!entry) {
    throw new Error(`Entry not found in dataset: ${relPath}`);
  }

  const basename = path.basename(relPath);
  const filePath = path.join(baseAssetsDir, relPath);
  const fileSize = fs.existsSync(filePath) ? fs.statSync(filePath).size : 0;
  const { epoch, dayid } = parseExifDate(entry.exif.DateTimeOriginal || '');

  const exif: Record<string, unknown> = {};

  if (entry.exif.DateTimeOriginal) {
    const rawDate = entry.exif.DateTimeOriginal.split('+')[0].replace(/Z$/, '');
    exif.CreateDate = rawDate;
    exif.DateTimeOriginal = rawDate;
    if (entry.exif.DateTimeOriginal.includes('+')) {
      const offset = '+' + entry.exif.DateTimeOriginal.split('+')[1];
      exif.OffsetTime = offset;
      exif.OffsetTimeOriginal = offset;
    } else if (entry.exif.DateTimeOriginal.endsWith('Z')) {
      exif.OffsetTime = '+00:00';
      exif.OffsetTimeOriginal = '+00:00';
    }
  }

  if (entry.exif.GPSLatitude !== undefined) {
    exif.GPSLatitude = entry.exif.GPSLatitude;
  }
  if (entry.exif.GPSLongitude !== undefined) {
    exif.GPSLongitude = entry.exif.GPSLongitude;
  }

  exif.Megapixels = parseFloat(((entry.size[0] * entry.size[1]) / 1000000).toFixed(4));

  return {
    fileid: 0,
    dayid,
    w: entry.size[0],
    h: entry.size[1],
    datetaken: epoch,
    exif,
    etag: '<etag>',
    permissions: 'RUDS',
    mimetype: 'image/jpeg',
    size: fileSize,
    basename,
    mtime: 0,
    owneruid: '<uid>',
    ownername: '<uid>',
    filename: relPath.replace(/^primary/, ''),
  };
}
