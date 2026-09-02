// Generate geo-tagged test dataset for e2e tests.
// Usage: npx tsx e2e/generate-geo-dataset.ts

import * as fs from 'fs';
import * as path from 'path';
import { execFileSync } from 'child_process';
import { createCanvas } from '@napi-rs/canvas';
import geoDatasetJson from './geo-dataset.json';

export interface IGeoEntry {
  date: string;
  coords: number[]; // [lat, lon]
  place: string;
  city: string;
}

export const GEO_DATASET = geoDatasetJson satisfies IGeoEntry[];

export const GEO_DATASET_FILES: Record<string, IGeoEntry> = Object.fromEntries(
  GEO_DATASET.map((entry, index) => [`geo-test-${String(index + 1).padStart(3, '0')}.jpg`, entry]),
);

/**
 * Generate a 256x256 JPEG image with the place name and city rendered.
 */
function generateImage(entry: IGeoEntry, filename: string): Buffer {
  const canvas = createCanvas(256, 256);
  const ctx = canvas.getContext('2d');

  // Background
  const hash = Array.from(filename).reduce((acc, char) => acc + char.charCodeAt(0), 0);
  const hue = (hash * 37) % 360;
  ctx.fillStyle = `hsl(${hue}, 20%, 94%)`;
  ctx.fillRect(0, 0, 256, 256);

  // Border
  ctx.strokeStyle = `hsl(${hue}, 35%, 65%)`;
  ctx.lineWidth = 4;
  ctx.strokeRect(2, 2, 252, 252);

  // Top header: filename
  ctx.fillStyle = '#6b7280';
  ctx.font = '12px sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(filename, 128, 40);

  // Place name above in black
  ctx.fillStyle = '#000000';
  ctx.font = 'bold 16px sans-serif';
  ctx.fillText(entry.place, 128, 95);

  // Coarser place / City below it in blue
  ctx.fillStyle = '#1d4ed8';
  ctx.font = 'bold 15px sans-serif';
  ctx.fillText(entry.city, 128, 125);

  // Coordinates and Date
  ctx.fillStyle = '#4b5563';
  ctx.font = '11px sans-serif';
  ctx.fillText(`${entry.coords[0].toFixed(4)}, ${entry.coords[1].toFixed(4)}`, 128, 180);
  ctx.fillText(entry.date.split(' ')[0], 128, 202);

  return canvas.toBuffer('image/jpeg', 85);
}

async function main() {
  const targetDir = path.join(__dirname, 'assets', 'primary', 'geo-test');

  // Delete the directory before beginning if it exists
  if (fs.existsSync(targetDir)) {
    fs.rmSync(targetDir, { recursive: true, force: true });
  }
  fs.mkdirSync(targetDir, { recursive: true });

  console.log(`Generating ${Object.keys(GEO_DATASET_FILES).length} images in ${targetDir}...`);

  const metadataList: Record<string, unknown>[] = [];

  for (const [filename, entry] of Object.entries(GEO_DATASET_FILES)) {
    const filePath = path.join(targetDir, filename);

    const buf = generateImage(entry, filename);
    fs.writeFileSync(filePath, Uint8Array.from(buf));

    const [lat, lon] = entry.coords;
    const exifDate = entry.date.replace(/-/g, ':');

    metadataList.push({
      SourceFile: filePath,
      DateTimeOriginal: exifDate,
      CreateDate: exifDate,
      GPSLatitude: Math.abs(lat),
      GPSLatitudeRef: lat >= 0 ? 'N' : 'S',
      GPSLongitude: Math.abs(lon),
      GPSLongitudeRef: lon >= 0 ? 'E' : 'W',
    });
  }

  // Write EXIF tags in bulk using exiftool
  const exiftool = path.join(__dirname, '..', 'bin-ext', 'exiftool', 'exiftool');
  const metaJsonPath = path.join(targetDir, 'exif_metadata_tmp.json');
  fs.writeFileSync(metaJsonPath, JSON.stringify(metadataList, null, 2));

  console.log(`Writing EXIF coordinates in bulk with ${exiftool}...`);
  try {
    execFileSync(exiftool, ['-overwrite_original', `-json=${metaJsonPath}`, targetDir], {
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

  console.log('Dataset generation completed successfully!');
}

if (require.main === module) {
  main().catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
