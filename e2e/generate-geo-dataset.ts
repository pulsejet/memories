// Generate geo-tagged test dataset for e2e tests.
// Usage: npx tsx e2e/generate-dataset.ts

import * as fs from 'fs';
import * as path from 'path';
import { execFileSync } from 'child_process';
import { createCanvas } from '@napi-rs/canvas';

export interface IGeoEntry {
  date: string;
  coords: [number, number]; // [lat, lon]
  place: string;
  city: string;
}

export const GEO_DATASET: IGeoEntry[] = [
  // 1-10: Downtown Los Angeles, California, USA
  { date: '2023-05-10 09:15:00', coords: [34.0537, -118.2427], place: 'LA City Hall', city: 'Los Angeles' },
  { date: '2023-05-10 11:30:00', coords: [34.0507, -118.2492], place: 'Grand Central Market', city: 'Los Angeles' },
  { date: '2023-05-10 14:00:00', coords: [34.0553, -118.2498], place: 'Walt Disney Concert Hall', city: 'Los Angeles' },
  { date: '2023-05-10 16:45:00', coords: [34.0545, -118.2505], place: 'The Broad', city: 'Los Angeles' },
  { date: '2023-05-11 10:00:00', coords: [34.0562, -118.2365], place: 'Union Station LA', city: 'Los Angeles' },
  { date: '2023-05-11 12:30:00', coords: [34.0498, -118.2398], place: 'Little Tokyo', city: 'Los Angeles' },
  { date: '2023-05-11 15:15:00', coords: [34.0505, -118.2479], place: 'Bradbury Building', city: 'Los Angeles' },
  { date: '2023-05-12 18:30:00', coords: [34.043, -118.2673], place: 'Crypto.com Arena', city: 'Los Angeles' },
  { date: '2023-05-12 20:00:00', coords: [34.0448, -118.2652], place: 'LA Live', city: 'Los Angeles' },
  { date: '2023-05-12 21:45:00', coords: [34.0418, -118.2325], place: 'Arts District LA', city: 'Los Angeles' },

  // 11-20: Santa Monica & Venice, California, USA
  { date: '2023-05-15 10:00:00', coords: [34.0099, -118.4965], place: 'Santa Monica Pier', city: 'Santa Monica' },
  { date: '2023-05-15 12:15:00', coords: [34.0158, -118.496], place: 'Third Street Promenade', city: 'Santa Monica' },
  { date: '2023-05-15 14:30:00', coords: [34.0175, -118.5012], place: 'Palisades Park', city: 'Santa Monica' },
  { date: '2023-05-15 16:00:00', coords: [34.013, -118.494], place: 'Ocean Avenue', city: 'Santa Monica' },
  { date: '2023-05-16 09:30:00', coords: [33.985, -118.4695], place: 'Venice Beach Boardwalk', city: 'Venice' },
  { date: '2023-05-16 11:45:00', coords: [33.9842, -118.4648], place: 'Venice Canals', city: 'Venice' },
  { date: '2023-05-16 14:00:00', coords: [33.9912, -118.461], place: 'Abbot Kinney Blvd', city: 'Venice' },
  { date: '2023-05-16 15:30:00', coords: [33.9875, -118.473], place: 'Muscle Beach Venice', city: 'Venice' },
  { date: '2023-05-16 17:15:00', coords: [34.0205, -118.508], place: 'Santa Monica Beach', city: 'Santa Monica' },
  { date: '2023-05-16 19:00:00', coords: [34.004, -118.4845], place: 'Main Street Santa Monica', city: 'Santa Monica' },

  // 21-30: San Francisco, California, USA
  { date: '2023-06-01 09:00:00', coords: [37.8199, -122.4783], place: 'Golden Gate Bridge', city: 'San Francisco' },
  { date: '2023-06-01 11:30:00', coords: [37.808, -122.4177], place: "Fisherman's Wharf", city: 'San Francisco' },
  { date: '2023-06-01 13:45:00', coords: [37.8087, -122.4098], place: 'Pier 39 SF', city: 'San Francisco' },
  { date: '2023-06-01 16:00:00', coords: [37.8269, -122.423], place: 'Alcatraz Island', city: 'San Francisco' },
  { date: '2023-06-02 10:15:00', coords: [37.8021, -122.4187], place: 'Lombard Street', city: 'San Francisco' },
  { date: '2023-06-02 12:30:00', coords: [37.7941, -122.4078], place: 'Chinatown SF', city: 'San Francisco' },
  { date: '2023-06-02 15:00:00', coords: [37.7955, -122.3937], place: 'Ferry Building', city: 'San Francisco' },
  { date: '2023-06-03 09:30:00', coords: [37.7544, -122.4477], place: 'Twin Peaks SF', city: 'San Francisco' },
  { date: '2023-06-03 12:00:00', coords: [37.7763, -122.4328], place: 'Painted Ladies', city: 'San Francisco' },
  { date: '2023-06-03 15:30:00', coords: [37.7596, -122.4269], place: 'Mission Dolores Park', city: 'San Francisco' },

  // 31-40: New York City, New York, USA
  { date: '2023-07-10 10:00:00', coords: [40.758, -73.9855], place: 'Times Square', city: 'New York' },
  { date: '2023-07-10 12:30:00', coords: [40.7851, -73.9683], place: 'Central Park', city: 'New York' },
  { date: '2023-07-10 15:00:00', coords: [40.7484, -73.9857], place: 'Empire State Building', city: 'New York' },
  { date: '2023-07-10 18:00:00', coords: [40.7061, -73.9969], place: 'Brooklyn Bridge', city: 'New York' },
  { date: '2023-07-11 09:30:00', coords: [40.6892, -74.0445], place: 'Statue of Liberty', city: 'New York' },
  { date: '2023-07-11 12:00:00', coords: [40.7587, -73.9787], place: 'Rockefeller Center', city: 'New York' },
  { date: '2023-07-11 14:30:00', coords: [40.7527, -73.9772], place: 'Grand Central Terminal', city: 'New York' },
  { date: '2023-07-12 10:15:00', coords: [40.748, -74.0048], place: 'The High Line', city: 'New York' },
  { date: '2023-07-12 13:00:00', coords: [40.7127, -74.0134], place: 'One World Trade Center', city: 'New York' },
  { date: '2023-07-12 16:30:00', coords: [40.7033, -73.988], place: 'DUMBO Brooklyn', city: 'New York' },

  // 41-50: Paris, France
  { date: '2023-08-05 09:30:00', coords: [48.8584, 2.2945], place: 'Eiffel Tower', city: 'Paris' },
  { date: '2023-08-05 12:00:00', coords: [48.8606, 2.3376], place: 'Louvre Museum', city: 'Paris' },
  { date: '2023-08-05 15:30:00', coords: [48.853, 2.3499], place: 'Notre-Dame Cathedral', city: 'Paris' },
  { date: '2023-08-05 18:00:00', coords: [48.8738, 2.295], place: 'Arc de Triomphe', city: 'Paris' },
  { date: '2023-08-06 10:00:00', coords: [48.8867, 2.3431], place: 'Sacré-Cœur Montmartre', city: 'Paris' },
  { date: '2023-08-06 13:15:00', coords: [48.8599, 2.3266], place: "Musée d'Orsay", city: 'Paris' },
  { date: '2023-08-06 16:00:00', coords: [48.8462, 2.3372], place: 'Jardin du Luxembourg', city: 'Paris' },
  { date: '2023-08-07 09:45:00', coords: [48.8554, 2.345], place: 'Sainte-Chapelle', city: 'Paris' },
  { date: '2023-08-07 13:00:00', coords: [48.8698, 2.3075], place: 'Champs-Élysées', city: 'Paris' },
  { date: '2023-08-07 16:30:00', coords: [48.8606, 2.3522], place: 'Centre Pompidou', city: 'Paris' },

  // 51-60: London, United Kingdom
  { date: '2023-08-10 09:30:00', coords: [51.5007, -0.1246], place: 'Big Ben', city: 'London' },
  { date: '2023-08-10 11:45:00', coords: [51.5081, -0.0759], place: 'Tower of London', city: 'London' },
  { date: '2023-08-10 14:00:00', coords: [51.5055, -0.0754], place: 'Tower Bridge', city: 'London' },
  { date: '2023-08-10 16:30:00', coords: [51.5033, -0.1195], place: 'London Eye', city: 'London' },
  { date: '2023-08-11 10:00:00', coords: [51.5014, -0.1419], place: 'Buckingham Palace', city: 'London' },
  { date: '2023-08-11 12:30:00', coords: [51.5194, -0.127], place: 'British Museum', city: 'London' },
  { date: '2023-08-11 15:00:00', coords: [51.508, -0.1281], place: 'Trafalgar Square', city: 'London' },
  { date: '2023-08-12 09:45:00', coords: [51.5138, -0.0984], place: "St Paul's Cathedral", city: 'London' },
  { date: '2023-08-12 12:30:00', coords: [51.5073, -0.1657], place: 'Hyde Park London', city: 'London' },
  { date: '2023-08-12 15:15:00', coords: [51.4994, -0.1273], place: 'Westminster Abbey', city: 'London' },

  // 61-70: Tokyo, Japan
  { date: '2023-09-15 09:00:00', coords: [35.6595, 139.7005], place: 'Shibuya Crossing', city: 'Tokyo' },
  { date: '2023-09-15 11:30:00', coords: [35.6586, 139.7454], place: 'Tokyo Tower', city: 'Tokyo' },
  { date: '2023-09-15 14:00:00', coords: [35.7148, 139.7967], place: 'Senso-ji Temple', city: 'Tokyo' },
  { date: '2023-09-15 16:45:00', coords: [35.6764, 139.6993], place: 'Meiji Shrine', city: 'Tokyo' },
  { date: '2023-09-16 10:00:00', coords: [35.6852, 139.71], place: 'Shinjuku Gyoen', city: 'Tokyo' },
  { date: '2023-09-16 13:00:00', coords: [35.6984, 139.773], place: 'Akihabara Electric Town', city: 'Tokyo' },
  { date: '2023-09-16 16:00:00', coords: [35.7101, 139.8107], place: 'Tokyo Skytree', city: 'Tokyo' },
  { date: '2023-09-17 09:30:00', coords: [35.7146, 139.7732], place: 'Ueno Park', city: 'Tokyo' },
  { date: '2023-09-17 13:15:00', coords: [35.6605, 139.7292], place: 'Roppongi Hills', city: 'Tokyo' },
  { date: '2023-09-17 16:30:00', coords: [35.6719, 139.7648], place: 'Ginza District', city: 'Tokyo' },

  // 71-80: Kyoto, Japan
  { date: '2023-09-19 09:00:00', coords: [34.9671, 135.7727], place: 'Fushimi Inari-taisha', city: 'Kyoto' },
  { date: '2023-09-19 11:30:00', coords: [35.0394, 135.7292], place: 'Kinkaku-ji', city: 'Kyoto' },
  { date: '2023-09-19 14:15:00', coords: [34.9949, 135.785], place: 'Kiyomizu-dera', city: 'Kyoto' },
  { date: '2023-09-19 16:45:00', coords: [35.0169, 135.6712], place: 'Arashiyama Bamboo Grove', city: 'Kyoto' },
  { date: '2023-09-20 09:30:00', coords: [35.0037, 135.7772], place: 'Gion District', city: 'Kyoto' },
  { date: '2023-09-20 12:00:00', coords: [35.0142, 135.7482], place: 'Nijo Castle', city: 'Kyoto' },
  { date: '2023-09-20 14:30:00', coords: [35.0037, 135.7785], place: 'Yasaka Shrine', city: 'Kyoto' },
  { date: '2023-09-21 09:15:00', coords: [35.0272, 135.7982], place: 'Ginkaku-ji', city: 'Kyoto' },
  { date: '2023-09-21 11:45:00', coords: [35.0225, 135.794], place: "Philosopher's Path", city: 'Kyoto' },
  { date: '2023-09-21 14:30:00', coords: [35.0157, 135.6776], place: 'Tenryu-ji', city: 'Kyoto' },

  // 81-90: Rome, Italy
  { date: '2023-10-05 09:00:00', coords: [41.8902, 12.4922], place: 'Colosseum', city: 'Rome' },
  { date: '2023-10-05 11:30:00', coords: [41.9009, 12.4833], place: 'Trevi Fountain', city: 'Rome' },
  { date: '2023-10-05 14:00:00', coords: [41.8986, 12.4769], place: 'Pantheon', city: 'Rome' },
  { date: '2023-10-05 16:30:00', coords: [41.8925, 12.4853], place: 'Roman Forum', city: 'Rome' },
  { date: '2023-10-06 09:30:00', coords: [41.8992, 12.4731], place: 'Piazza Navona', city: 'Rome' },
  { date: '2023-10-06 12:00:00', coords: [41.906, 12.4828], place: 'Spanish Steps', city: 'Rome' },
  { date: '2023-10-06 14:30:00', coords: [41.9031, 12.4663], place: "Castel Sant'Angelo", city: 'Rome' },
  { date: '2023-10-07 09:15:00', coords: [41.9022, 12.4568], place: "St. Peter's Square", city: 'Rome' },
  { date: '2023-10-07 12:30:00', coords: [41.9067, 12.4534], place: 'Vatican Museums', city: 'Rome' },
  { date: '2023-10-07 15:45:00', coords: [41.9142, 12.4922], place: 'Villa Borghese', city: 'Rome' },

  // 91-100: Sydney, Australia
  { date: '2023-11-12 09:00:00', coords: [-33.8568, 151.2153], place: 'Sydney Opera House', city: 'Sydney' },
  { date: '2023-11-12 11:30:00', coords: [-33.8523, 151.2108], place: 'Sydney Harbour Bridge', city: 'Sydney' },
  { date: '2023-11-12 14:00:00', coords: [-33.8915, 151.2767], place: 'Bondi Beach', city: 'Sydney' },
  { date: '2023-11-12 16:30:00', coords: [-33.8599, 151.209], place: 'The Rocks', city: 'Sydney' },
  { date: '2023-11-13 10:00:00', coords: [-33.8749, 151.2009], place: 'Darling Harbour', city: 'Sydney' },
  { date: '2023-11-13 12:30:00', coords: [-33.8642, 151.2166], place: 'Royal Botanic Garden', city: 'Sydney' },
  { date: '2023-11-13 15:00:00', coords: [-33.8434, 151.2413], place: 'Taronga Zoo', city: 'Sydney' },
  { date: '2023-11-14 09:30:00', coords: [-33.7972, 151.2882], place: 'Manly Beach', city: 'Sydney' },
  { date: '2023-11-14 12:15:00', coords: [-33.8612, 151.2108], place: 'Circular Quay', city: 'Sydney' },
  { date: '2023-11-14 15:00:00', coords: [-33.92, 151.258], place: 'Coogee Beach', city: 'Sydney' },
];

/**
 * Generate a 256x256 JPEG image with the place name and city rendered.
 */
function generateImage(entry: IGeoEntry, index: number): Buffer {
  const canvas = createCanvas(256, 256);
  const ctx = canvas.getContext('2d');

  // Background
  const hue = (index * 37) % 360;
  ctx.fillStyle = `hsl(${hue}, 20%, 94%)`;
  ctx.fillRect(0, 0, 256, 256);

  // Border
  ctx.strokeStyle = `hsl(${hue}, 35%, 65%)`;
  ctx.lineWidth = 4;
  ctx.strokeRect(2, 2, 252, 252);

  // Top header: image index
  const numStr = `geo-test-${String(index).padStart(3, '0')}`;
  ctx.fillStyle = '#6b7280';
  ctx.font = '12px sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(numStr, 128, 40);

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

  console.log(`Generating ${GEO_DATASET.length} images in ${targetDir}...`);

  const metadataList: Record<string, unknown>[] = [];

  GEO_DATASET.forEach((entry, i) => {
    const index = i + 1;
    const filename = `geo-test-${String(index).padStart(3, '0')}.jpg`;
    const filePath = path.join(targetDir, filename);

    const buf = generateImage(entry, index);
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
  });

  // Write EXIF tags in bulk using exiftool
  const exiftool = path.join(__dirname, '..', 'bin-ext', 'exiftool', 'exiftool');
  const metaJsonPath = path.join(targetDir, 'exif_metadata_tmp.json');
  fs.writeFileSync(metaJsonPath, JSON.stringify(metadataList, null, 2));

  console.log(`Writing EXIF coordinates in bulk with ${exiftool}...`);
  try {
    execFileSync(exiftool, ['-overwrite_original', `-json=${metaJsonPath}`, targetDir], {
      stdio: 'inherit',
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
