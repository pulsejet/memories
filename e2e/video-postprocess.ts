#!/usr/bin/env tsx

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync, spawnSync } from 'node:child_process';

const INPUT_FILE = process.argv[2] || 'playwright-results.json';
const OUTPUT_FILE = process.argv[3] || 'playwright-results.mp4';

interface VideoItem {
  title: string;
  path: string;
}

function findVideos(suite: any): VideoItem[] {
  let list: VideoItem[] = [];
  if (suite.specs) {
    for (const spec of suite.specs) {
      for (const test of spec.tests || []) {
        for (const result of test.results || []) {
          for (const attachment of result.attachments || []) {
            if (attachment.name === 'video' && fs.existsSync(attachment.path)) {
              list.push({ title: spec.title, path: attachment.path });
            }
          }
        }
      }
    }
  }
  if (suite.suites) {
    for (const child of suite.suites) {
      list = list.concat(findVideos(child));
    }
  }
  return list;
}

interface BlankInterval {
  start: number;
  end: number;
  duration: number;
}

function getBlankIntervals(videoPath: string): BlankInterval[] {
  const res = spawnSync('ffmpeg', ['-i', videoPath, '-vf', 'negate,blackdetect=d=0.1:pix_th=0.10', '-f', 'null', '-']);
  const stderr = res.stderr?.toString() || '';
  const intervals: BlankInterval[] = [];
  const regex = /black_start:([0-9.]+) black_end:([0-9.]+) black_duration:([0-9.]+)/g;
  let match: RegExpExecArray | null;
  while ((match = regex.exec(stderr)) !== null) {
    intervals.push({
      start: parseFloat(match[1]),
      end: parseFloat(match[2]),
      duration: parseFloat(match[3]),
    });
  }
  return intervals;
}

function getVideoDuration(videoPath: string): number {
  const res = spawnSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', videoPath]);
  return parseFloat(res.stdout?.toString().trim()) || 0;
}

function main() {
  if (!fs.existsSync(INPUT_FILE)) {
    console.error(`File not found: ${INPUT_FILE}`);
    process.exit(1);
  }

  const data = JSON.parse(fs.readFileSync(INPUT_FILE, 'utf-8'));
  const videos: VideoItem[] = [];
  for (const suite of data.suites || []) {
    videos.push(...findVideos(suite));
  }

  if (videos.length === 0) {
    console.log('No video attachments found.');
    return;
  }

  console.log(`Found ${videos.length} videos. Combining into ${OUTPUT_FILE}...`);

  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pw-combine-'));
  try {
    const intermediateClips: string[] = [];

    videos.forEach((video, index) => {
      const dur = getVideoDuration(video.path);
      const blankIntervals = getBlankIntervals(video.path);
      const totalBlank = blankIntervals.reduce((acc, i) => acc + i.duration, 0);

      // Skip if entire clip is blank white frames
      if (blankIntervals.length > 0 && dur - totalBlank < 0.25) {
        console.log(`[${index + 1}/${videos.length}] ${video.title} (skipped: blank)`);
        return;
      }

      const textFile = path.join(tempDir, `label_${index}.txt`);
      fs.writeFileSync(textFile, video.title, 'utf-8');

      const clipOutput = path.join(tempDir, `clip_${index}.mp4`);
      intermediateClips.push(clipOutput);

      console.log(`[${index + 1}/${videos.length}] ${video.title}`);

      // Overlay test name in the upper left corner and normalize clip
      const drawTextFilter = [
        `textfile='${textFile}'`,
        'expansion=none',
        'x=24',
        'y=24',
        'fontsize=24',
        'fontcolor=white',
        'box=1',
        'boxcolor=black@0.65',
        'boxborderw=10',
      ].join(':');

      const filterList: string[] = [];

      // Filter out blank white intervals detected via negate,blackdetect
      if (blankIntervals.length > 0) {
        const selectExpr = blankIntervals.map((i) => `between(t\\,${i.start}\\,${i.end})`).join('+');
        filterList.push(`select='not(${selectExpr})'`, 'setpts=N/FRAME_RATE/TB');
      }

      filterList.push(
        'scale=1280:720:force_original_aspect_ratio=decrease',
        'pad=1280:720:(ow-iw)/2:(oh-ih)/2',
        'setsar=1',
        'fps=25',
        'format=yuv420p',
        `drawtext=${drawTextFilter}`,
      );

      const videoFilters = filterList.join(',');

      execFileSync(
        'ffmpeg',
        [
          '-y',
          '-i',
          video.path,
          '-vf',
          videoFilters,
          '-c:v',
          'libx264',
          '-preset',
          'veryfast',
          '-crf',
          '22',
          '-pix_fmt',
          'yuv420p',
          '-an',
          clipOutput,
        ],
        { stdio: ['ignore', 'ignore', 'pipe'] },
      );
    });

    const listFile = path.join(tempDir, 'list.txt');
    fs.writeFileSync(listFile, intermediateClips.map((c) => `file '${c.replace(/'/g, "'\\''")}'`).join('\n'), 'utf-8');

    execFileSync(
      'ffmpeg',
      ['-y', '-f', 'concat', '-safe', '0', '-i', listFile, '-c', 'copy', '-movflags', '+faststart', OUTPUT_FILE],
      { stdio: ['ignore', 'ignore', 'pipe'] },
    );

    console.log(`Done! Output saved to ${OUTPUT_FILE}`);
  } finally {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
}

main();
