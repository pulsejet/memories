// Generic synthetic dataset generator for e2e tests.
// Usage: npx tsx e2e/generate-dataset.ts [dataset.json]

import * as fs from 'fs';
import * as path from 'path';
import { execFileSync } from 'child_process';
import { createCanvas } from '@napi-rs/canvas';

import defaultDatasetJson from './dataset.json';

export interface IDatasetExif {
  DateTimeOriginal?: string;
  GPSLatitude?: number;
  GPSLongitude?: number;
  [key: string]: unknown;
}

export interface IDatasetParams {
  place?: string;
  city?: string;
  [key: string]: unknown;
}

export interface IDatasetEntry {
  size: number[]; // [width, height]
  exif: IDatasetExif;
  params?: IDatasetParams;
}

export type IDatasetMap = Record<string, IDatasetEntry>;

export const DEFAULT_DATASET = defaultDatasetJson satisfies IDatasetMap;

/**
 * Generate a JPEG image buffer based on dataset entry specifications.
 */
export function generateImage(entry: IDatasetEntry, filename: string): Buffer {
  const [w, h] = entry.size;
  const canvas = createCanvas(w, h);
  const ctx = canvas.getContext('2d');

  // Background
  const hash = Array.from(filename).reduce((acc, char) => acc + char.charCodeAt(0), 0);
  const hue = (hash * 37) % 360;
  ctx.fillStyle = `hsl(${hue}, 20%, 94%)`;
  ctx.fillRect(0, 0, w, h);

  // Border
  ctx.strokeStyle = `hsl(${hue}, 35%, 65%)`;
  ctx.lineWidth = Math.min(4, Math.max(1, Math.min(w, h) / 10));
  ctx.strokeRect(2, 2, w - 4, h - 4);

  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const midX = w / 2;

  // Render text based on presence of geo params vs generic metadata
  if (entry.params?.place || entry.params?.city) {
    const fontSize = Math.max(10, Math.min(16, Math.floor(h / 16)));

    ctx.fillStyle = '#6b7280';
    ctx.font = `${Math.max(9, Math.floor(fontSize * 0.75))}px sans-serif`;
    ctx.fillText(filename, midX, h * 0.16);

    if (entry.params.place) {
      ctx.fillStyle = '#000000';
      ctx.font = `bold ${fontSize}px sans-serif`;
      ctx.fillText(entry.params.place, midX, h * 0.38);
    }

    if (entry.params.city) {
      ctx.fillStyle = '#1d4ed8';
      ctx.font = `bold ${Math.max(9, Math.floor(fontSize * 0.9))}px sans-serif`;
      ctx.fillText(entry.params.city, midX, h * 0.52);
    }

    ctx.fillStyle = '#4b5563';
    ctx.font = `${Math.max(8, Math.floor(fontSize * 0.7))}px sans-serif`;
    if (entry.exif.GPSLatitude !== undefined && entry.exif.GPSLongitude !== undefined) {
      ctx.fillText(`${entry.exif.GPSLatitude.toFixed(4)}, ${entry.exif.GPSLongitude.toFixed(4)}`, midX, h * 0.72);
    }
    if (entry.exif.DateTimeOriginal) {
      ctx.fillText(entry.exif.DateTimeOriginal.split(' ')[0].replace(/:/g, '-'), midX, h * 0.85);
    }
  } else {
    const baseFontSize = Math.max(9, Math.min(14, Math.floor(Math.min(w, h) / 8)));

    ctx.fillStyle = '#1f2937';
    ctx.font = `bold ${baseFontSize}px sans-serif`;
    ctx.fillText(filename, midX, h * 0.4);

    if (entry.exif.DateTimeOriginal) {
      ctx.fillStyle = '#4b5563';
      ctx.font = `${Math.max(8, Math.floor(baseFontSize * 0.85))}px sans-serif`;
      ctx.fillText(entry.exif.DateTimeOriginal.split(' ')[0].replace(/:/g, '-'), midX, h * 0.65);
    }
  }

  return canvas.toBuffer('image/jpeg', 85);
}

/**
 * Generate images and set EXIF tags for a given dataset map.
 */
export async function generateDataset(dataset: IDatasetMap, baseAssetsDir: string): Promise<void> {
  const metadataList: Record<string, unknown>[] = [];
  const modifiedDirs = new Set<string>();

  for (const [relPath, entry] of Object.entries(dataset)) {
    const targetPath = path.join(baseAssetsDir, relPath);
    const targetDir = path.dirname(targetPath);

    fs.mkdirSync(targetDir, { recursive: true });
    modifiedDirs.add(targetDir);

    const filename = path.basename(relPath);
    const buf = generateImage(entry, filename);
    fs.writeFileSync(targetPath, Uint8Array.from(buf));

    const meta: Record<string, unknown> = {
      SourceFile: targetPath,
    };

    if (entry.exif.DateTimeOriginal) {
      const exifDate = entry.exif.DateTimeOriginal.replace(/-/g, ':');
      meta.DateTimeOriginal = exifDate;
      meta.CreateDate = exifDate;
      if (entry.exif.DateTimeOriginal.includes('+')) {
        const offset = '+' + entry.exif.DateTimeOriginal.split('+')[1];
        meta.OffsetTimeOriginal = offset;
        meta.OffsetTime = offset;
      } else if (entry.exif.DateTimeOriginal.endsWith('Z')) {
        meta.OffsetTimeOriginal = '+00:00';
        meta.OffsetTime = '+00:00';
      }
    }

    if (entry.exif.GPSLatitude !== undefined && entry.exif.GPSLongitude !== undefined) {
      const lat = entry.exif.GPSLatitude;
      const lon = entry.exif.GPSLongitude;
      meta.GPSLatitude = Math.abs(lat);
      meta.GPSLatitudeRef = lat >= 0 ? 'N' : 'S';
      meta.GPSLongitude = Math.abs(lon);
      meta.GPSLongitudeRef = lon >= 0 ? 'E' : 'W';
    }

    metadataList.push(meta);
  }

  // Write EXIF tags in bulk using exiftool
  const exiftool = path.join(__dirname, '..', 'bin-ext', 'exiftool', 'exiftool');
  const metaJsonPath = path.join(baseAssetsDir, 'exif_metadata_tmp.json');
  fs.writeFileSync(metaJsonPath, JSON.stringify(metadataList, null, 2));

  try {
    const dirArgs = Array.from(modifiedDirs);
    execFileSync(exiftool, ['-overwrite_original', `-json=${metaJsonPath}`, ...dirArgs], {
      stdio: 'inherit',
      env: {
        ...process.env,
        LC_ALL: 'C',
      },
    });
  } finally {
    if (fs.existsSync(metaJsonPath)) {
      fs.unlinkSync(metaJsonPath);
    }
  }
}

async function main() {
  const baseAssetsDir = path.join(__dirname, 'assets');
  const datasetFile = process.argv[2] ? path.resolve(process.argv[2]) : null;

  let dataset: IDatasetMap;
  if (datasetFile) {
    dataset = JSON.parse(fs.readFileSync(datasetFile, 'utf-8'));
  } else {
    dataset = DEFAULT_DATASET;
  }

  console.log(`Generating ${Object.keys(dataset).length} dataset images in ${baseAssetsDir}...`);
  await generateDataset(dataset, baseAssetsDir);
  console.log('Dataset generation completed successfully!');
}

if (require.main === module) {
  main().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
